/**
 * Talking to the server.
 *
 * Every call goes to a URL the page was handed, already signed and already
 * scoped to this run of this scene. The player therefore holds no credential of
 * its own: there is no token in the page for a shared phone to keep, and a link
 * that leaks is a link to one scene for a short while.
 */
export class SceneApi {
    constructor(endpoints) {
        this.endpoints = endpoints;
    }

    async answer(beatId, payload) {
        return this.post(this.endpoints.answer.replace('__BEAT__', String(beatId)), payload);
    }

    async finish() {
        return this.post(this.endpoints.finish, {});
    }

    async state() {
        const response = await fetch(this.endpoints.state, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        return this.unwrap(response);
    }

    async post(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        return this.unwrap(response);
    }

    async unwrap(response) {
        let json = null;
        try {
            json = await response.json();
        } catch {
            json = null;
        }

        if (!response.ok) {
            const error = json && json.error ? json.error : null;
            throw new Error(error?.message || `The server answered ${response.status}.`);
        }

        return json?.data ?? json;
    }
}
