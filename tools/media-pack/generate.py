#!/usr/bin/env python3
"""
Zaban media runner - generates the course artwork on your own machine.

Why this exists
---------------
The unlimited model packs on your Higgsfield account (Nano Banana 2, GPT Image,
Kling O1, Seedream, FLUX.2 ...) are attached to your signed-in account. The
remote session that planned this work could not reach them - every request it
made was billed against credits instead. Running the official Higgsfield CLI
under your own login is the way to find out whether those packs apply, and to
spend them if they do.

Nothing here scrapes or automates a browser. It drives `higgsfield`, the
vendor's own CLI, which you authenticate once with `higgsfield auth login`.

What it does
------------
  1. reads prompts.json (the render manifest, already prioritised)
  2. calls the CLI once per prompt, a few at a time
  3. downloads each finished image next to this script, under images/
  4. writes results.json as it goes, so an interrupted run resumes cleanly

Send results.json back to the project and `php artisan media:import` will
attach every image to the lesson, word or character it belongs to.

Usage
-----
  python3 generate.py --check            # auth, plan and cost, generate nothing
  python3 generate.py --limit 12         # a small first batch - do this first
  python3 generate.py --kind lesson_scene --limit 50
  python3 generate.py                    # everything still outstanding
  python3 generate.py --unlim            # spend the unlimited packs, not credits

Stop it at any time with Ctrl-C; re-running picks up where it stopped.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from threading import Lock

HERE = Path(__file__).resolve().parent
PROMPTS = HERE / "prompts.json"
RESULTS = HERE / "results.json"
IMAGES = HERE / "images"
FAILURES = HERE / "failures.json"

# Higgsfield rate-limits concurrent generations. Six is comfortably inside the
# published limit and leaves room for the widget/UI to keep working while this
# runs; raising it tends to produce refusals rather than throughput.
DEFAULT_WORKERS = 6

# A single image usually lands in 20-40s. The ceiling is for the tail, not the
# typical case.
WAIT_TIMEOUT = "10m"

_print_lock = Lock()
_results_lock = Lock()


def say(*args) -> None:
    with _print_lock:
        print(*args, flush=True)


def cli() -> str:
    """Locate the Higgsfield CLI, or explain how to install it."""
    for name in ("higgsfield", "higgs", "hf"):
        found = shutil.which(name)
        if found:
            return found

    sys.exit(
        "Higgsfield CLI not found.\n\n"
        "  npm i -g @higgsfield/cli\n"
        "  higgsfield auth login\n"
    )


def run_cli(args: list[str], timeout: int = 900) -> tuple[int, str, str]:
    proc = subprocess.run(
        [cli(), *args],
        capture_output=True,
        text=True,
        timeout=timeout,
    )
    return proc.returncode, proc.stdout.strip(), proc.stderr.strip()


def require_auth() -> None:
    code, out, err = run_cli(["auth", "token"], timeout=60)
    if code != 0 or not out:
        sys.exit(
            "Not signed in to Higgsfield.\n\n"
            "  higgsfield auth login\n\n"
            "It opens your browser; finish the sign-in there, then run this again."
        )


def account_status() -> str:
    code, out, err = run_cli(["account", "status"], timeout=60)
    return out or err or "(could not read account status)"


def load_prompts() -> list[dict]:
    if not PROMPTS.exists():
        sys.exit(f"Missing {PROMPTS.name} - it should sit next to this script.")

    data = json.loads(PROMPTS.read_text(encoding="utf-8"))
    requests = data.get("requests")

    if not isinstance(requests, list) or not requests:
        sys.exit(f"{PROMPTS.name} contains no requests.")

    return requests


def load_results() -> dict:
    if not RESULTS.exists():
        return {"results": {}}

    try:
        data = json.loads(RESULTS.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        # A run killed mid-write. Keep the damaged file rather than deleting
        # it - it may still be readable by hand - and start a fresh one.
        RESULTS.rename(RESULTS.with_suffix(".json.corrupt"))
        return {"results": {}}

    data.setdefault("results", {})
    return data


def save_results(results: dict) -> None:
    """Write atomically: a crash mid-write must not destroy a long run."""
    tmp = RESULTS.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(results, indent=2, ensure_ascii=False), encoding="utf-8")
    tmp.replace(RESULTS)


def build_args(req: dict, use_unlim: bool, model_override: str | None) -> list[str]:
    params = dict(req["params"])
    model = model_override or params.pop("model")
    params.pop("model", None)

    args = ["generate", "create", model, "--json", "--wait", "--wait-timeout", WAIT_TIMEOUT]

    for key, value in params.items():
        if value in (None, ""):
            continue
        # The CLI takes params as --kebab-case flags.
        args += [f"--{key.replace('_', '-')}", str(value)]

    if use_unlim:
        args += ["--use-unlim", "true"]

    return args


def result_url(payload: str) -> str | None:
    """Pull the finished asset URL out of whatever shape the CLI returned."""
    try:
        data = json.loads(payload)
    except json.JSONDecodeError:
        return None

    stack = [data]
    while stack:
        node = stack.pop()
        if isinstance(node, dict):
            for key in ("result_url", "url", "output_url", "asset_url"):
                value = node.get(key)
                if isinstance(value, str) and value.startswith("http"):
                    return value
            stack.extend(node.values())
        elif isinstance(node, list):
            stack.extend(node)

    return None


def download(url: str, dest: Path) -> int:
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(dest.suffix + ".part")

    last_error: Exception | None = None
    for attempt in range(4):
        try:
            with urllib.request.urlopen(url, timeout=180) as response:
                body = response.read()

            if len(body) < 1024:
                raise ValueError(f"downloaded file is only {len(body)} bytes")

            tmp.write_bytes(body)
            tmp.replace(dest)
            return len(body)
        except Exception as exc:  # noqa: BLE001 - retried below
            last_error = exc
            time.sleep(2 ** attempt)

    raise RuntimeError(f"download failed after 4 attempts: {last_error}")


def extension_for(kind: str) -> str:
    return ".mp4" if kind.endswith("_video") else ".png"


def generate_one(req: dict, args_ns) -> tuple[str, dict]:
    index = str(req["index"])
    kind = req.get("kind", "image")
    dest = IMAGES / kind / f"{index}{extension_for(kind)}"

    if dest.exists() and dest.stat().st_size > 1024:
        return index, {"status": "already_downloaded", "file": str(dest.relative_to(HERE))}

    cli_args = build_args(req, args_ns.unlim, args_ns.model)
    code, out, err = run_cli(cli_args)

    if code != 0:
        message = err or out or "the CLI exited non-zero"

        # Asking for the unlimited packs on a model that has none is worth
        # saying once, clearly, rather than burying it in a failure list.
        if "unlim" in message.lower():
            return index, {"status": "failed", "error": message, "unlim_rejected": True}

        return index, {"status": "failed", "error": message[:500]}

    url = result_url(out)
    if not url:
        return index, {"status": "failed", "error": f"no asset URL in CLI output: {out[:300]}"}

    try:
        size = download(url, dest)
    except Exception as exc:  # noqa: BLE001 - reported per item
        return index, {"status": "failed", "error": str(exc), "url": url}

    return index, {
        "status": "ok",
        "url": url,
        "file": str(dest.relative_to(HERE)),
        "bytes": size,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate the Zaban course artwork locally.")
    parser.add_argument("--limit", type=int, help="stop after this many new generations")
    parser.add_argument("--kind", help="only this kind (lesson_scene, vocabulary_card, ...)")
    parser.add_argument("--model", help="override the model for every request")
    parser.add_argument("--workers", type=int, default=DEFAULT_WORKERS, help=f"parallel jobs (default {DEFAULT_WORKERS})")
    parser.add_argument("--unlim", action="store_true", help="spend the unlimited packs instead of credits")
    parser.add_argument("--check", action="store_true", help="report auth, plan and what is outstanding, then exit")
    parser.add_argument("--retry-failed", action="store_true", help="also retry entries that previously failed")
    args = parser.parse_args()

    require_auth()

    requests = load_prompts()
    store = load_results()
    done = store["results"]

    def outstanding(req: dict) -> bool:
        if args.kind and req.get("kind") != args.kind:
            return False

        entry = done.get(str(req["index"]))
        if entry is None:
            return True
        if entry.get("status") == "failed":
            return args.retry_failed

        return False

    todo = [r for r in requests if outstanding(r)]
    if args.limit:
        todo = todo[: args.limit]

    say(account_status())
    say("")
    say(f"manifest      : {len(requests)} planned")
    say(f"already done  : {sum(1 for v in done.values() if v.get('status') in ('ok', 'already_downloaded'))}")
    say(f"this run      : {len(todo)}")
    say(f"paying with   : {'unlimited packs' if args.unlim else 'credits'}")
    say("")

    if args.check:
        if todo:
            first = todo[0]
            code, out, err = run_cli(
                ["generate", "cost", args.model or first["params"]["model"],
                 "--prompt", first["params"]["prompt"][:200], "--json"],
                timeout=90,
            )
            say(f"cost of one {first.get('kind')}: {out or err}")
        return 0

    if not todo:
        say("Nothing outstanding.")
        return 0

    completed = failed = 0
    unlim_rejected = False

    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as pool:
        futures = {pool.submit(generate_one, req, args): req for req in todo}

        try:
            for future in as_completed(futures):
                index, outcome = future.result()

                with _results_lock:
                    done[index] = outcome
                    save_results(store)

                if outcome["status"] in ("ok", "already_downloaded"):
                    completed += 1
                    say(f"  [{completed + failed}/{len(todo)}] ok    {index}  {outcome.get('file', '')}")
                else:
                    failed += 1
                    unlim_rejected |= outcome.get("unlim_rejected", False)
                    say(f"  [{completed + failed}/{len(todo)}] FAIL  {index}  {outcome['error'][:120]}")
        except KeyboardInterrupt:
            say("\nStopping. Progress is saved - re-run to continue.")
            pool.shutdown(wait=False, cancel_futures=True)
            return 130

    say("")
    say(f"done: {completed}   failed: {failed}")

    if unlim_rejected:
        say("")
        say("The unlimited packs were refused for at least one model. That is the")
        say("same answer the remote session got, so the packs likely apply only")
        say("inside the Higgsfield web app. Re-run without --unlim to use credits,")
        say("or ask Higgsfield whether the packs cover API and CLI generations.")

    if failed:
        broken = {k: v for k, v in done.items() if v.get("status") == "failed"}
        FAILURES.write_text(json.dumps(broken, indent=2, ensure_ascii=False), encoding="utf-8")
        say(f"failures written to {FAILURES.name} - re-run with --retry-failed to try them again")

    say("")
    say(f"Send {RESULTS.name} back to the project, then run:")
    say("  php artisan media:import results.json")

    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
