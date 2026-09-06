"""
The stamps the scanned copies carry that are not part of the book.

Whoever digitised these files printed an address across every page, and it is
not the publisher's, not the author's, and not this project's. It has no
business in a lesson, in a search index, or in anything a learner reads, so it
is removed wherever text enters the project - from a page image, and from the
text layer of a PDF that already carried it.

Written loosely on purpose. A watermark is printed faintly, at an angle, over
the text, which makes it exactly the thing a reader gets wrong: one address
came back as four different strings across four pages. Matching only the exact
spelling let every one of the others through.
"""
import re

WATERMARKS = [
    re.compile(r'\w*ir\s*[l1|]anguage\s*[.,]?\s*(?:c[o0]m|c[o0]n|ir)?\w*', re.I),
    re.compile(r'\w*(?:www\.?)?shop\.?tabaeng[l1|]ish\.?\w*', re.I),
    re.compile(r'\w*(?:www\.?)?[l1|]anguagecentre(?:\.\w+)?\w*', re.I),
    re.compile(r'\btabaeng[l1|]ish\b', re.I),
]


def scrub(text: str) -> str:
    """Take the stamp out of a run of text, leaving the book behind."""
    for pattern in WATERMARKS:
        text = pattern.sub('', text)
    return text


def is_watermark(word: str) -> bool:
    """Is this word nothing but the stamp?"""
    stripped = word.strip(' .,;:()[]|')
    if not stripped:
        return False
    return any(pattern.fullmatch(stripped) for pattern in WATERMARKS)
