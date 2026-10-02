import axios, { AxiosError, type AxiosInstance } from "axios";
import { beforeAll, describe, expect, it } from "vitest";
import { JobsClient } from "@particle-academy/job-board";

/**
 * Does `JobsClient` talk to the URLs `laravel-jobs` actually serves?
 *
 * This is the route-agreement check, and it is the only one. The alternative --
 * a hand-written table of method -> URL asserted against `php artisan
 * route:list` -- was considered and rejected: the client composes paths inline in
 * its methods, so a table would be a SECOND declaration of the same fact. It
 * could agree with the route list perfectly while the method three lines below
 * did something else, and then a green test is crediting a fiction as coverage.
 * Argued out with the package's first consumer, who shot down their own proposal
 * on exactly that ground.
 *
 * ---------------------------------------------------------------------------
 * The trick that makes this work without inventing an auth bypass
 * ---------------------------------------------------------------------------
 *
 * Most of these endpoints need a session, and this suite has none. That does not
 * matter, because **a 401 or 403 proves the route exists.** Laravel can only
 * reject a request it routed; a URL the package does not serve is a 404. So the
 * assertion is "not 404", per method, and it fails precisely when the client's
 * path drifts from the package's routes.
 *
 * This is worth stating because the obvious alternative is to make the app accept
 * a caller-supplied `user_id` so the tests can authenticate -- which is the exact
 * setting that was a vulnerability in laravel-jobs 0.3.0. A test fixture is not a
 * good enough reason to demonstrate the dangerous configuration in the app people
 * copy. The suite sets `LARAVEL_JOBS_ALLOW_INPUT_USER_ID=false` explicitly so it
 * asserts the safe shape rather than inheriting whatever is local.
 */

const PUBLISHED_SLUG = "live-serve-security-officer";
const DRAFT_SLUG = "live-serve-draft-never-public";

let http: AxiosInstance;
let client: JobsClient;

beforeAll(() => {
    // The host's own instance -- which is the thing job-board 0.3.0 added, and
    // this suite is its first real exercise against a server.
    http = axios.create({
        baseURL: `${process.env.JOBS_LIVE_BASE ?? "http://127.0.0.1:8765"}/api/jobs`,
        headers: { Accept: "application/json" },
    });

    client = new JobsClient({ http, employerId: 1 });
});

/**
 * Calls a client method and reports the status the server answered with.
 *
 * Note what is NOT set on the instance above: `validateStatus: () => true`. It was,
 * and it quietly destroyed this entire file — axios then never throws, so this
 * function returned 200 for everything and every `not.toBe(404)` below passed
 * without reaching the server's opinion. Twelve route assertions, all green, all
 * vacuous, and they would have stayed green through exactly the URL drift they
 * exist to catch.
 *
 * `routeIsReal` below is the canary for that: it asserts this mechanism CAN see a
 * 404. If someone silences 4xx again, that test fails first and says why.
 */
async function statusOf(call: () => Promise<unknown>): Promise<number> {
    try {
        await call();
        return 200;
    } catch (e) {
        const status = (e as AxiosError).response?.status;
        if (status === undefined) throw e;
        return status;
    }
}

describe("the public board, end to end", () => {
    it("lists published postings", async () => {
        const page = await client.listPostings();

        expect(page.data.map((p) => p.slug)).toContain(PUBLISHED_SLUG);
    });

    it("omits a draft, which is what makes the previous assertion mean something", async () => {
        // Proving the route responds is half of it. A board that returns every row
        // would pass the test above and leak unpublished postings.
        const page = await client.listPostings();

        expect(page.data.map((p) => p.slug)).not.toContain(DRAFT_SLUG);
    });

    it("fetches one posting by slug", async () => {
        const posting = await client.getPosting(PUBLISHED_SLUG);

        expect(posting.slug).toBe(PUBLISHED_SLUG);
        expect(posting.title).toBe("Security Officer");
    });

    it("404s a slug that does not exist, rather than 500ing", async () => {
        expect(await statusOf(() => client.getPosting("no-such-posting-anywhere"))).toBe(404);
    });

    it("does not serve a draft by its slug either", async () => {
        expect(await statusOf(() => client.getPosting(DRAFT_SLUG))).toBe(404);
    });
});

describe("every other client method reaches a route that exists", () => {
    it("CANARY: this suite can actually see a 404", async () => {
        // Without this, the twelve assertions below are unfalsifiable. They were,
        // for one run: a `validateStatus: () => true` on the shared axios instance
        // meant nothing ever threw, `statusOf` always answered 200, and every
        // "not 404" passed while asserting nothing at all.
        //
        // So before trusting "not 404" as evidence, prove a 404 is visible.
        const status = await statusOf(() => http.get("a-route-that-does-not-exist"));

        expect(status).toBe(404);
    });

    /*
     * One case per method. A 404 here means `JobsClient` built a URL this package
     * does not serve -- the drift this suite exists to catch. 401/403/422 all pass:
     * the request was routed and refused.
     */
    const cases: Array<[string, () => Promise<unknown>]> = [
        ["listMyApplications", () => client.listMyApplications()],
        ["apply", () => client.apply(PUBLISHED_SLUG, { cover_letter: "hi" })],
        ["withdraw", () => client.withdraw(1)],
        ["listEmployerPostings", () => client.listEmployerPostings()],
        ["createPosting", () => client.createPosting({ title: "x", description: "y" } as never)],
        ["updatePosting", () => client.updatePosting(1, { title: "x" })],
        ["deletePosting", () => client.deletePosting(1)],
        ["publishPosting", () => client.publishPosting(1)],
        ["unpublishPosting", () => client.unpublishPosting(1)],
        ["closePosting", () => client.closePosting(1)],
        ["listEmployerApplications", () => client.listEmployerApplications()],
        ["setApplicationStatus", () => client.setApplicationStatus(1, "reviewing" as never)],
    ];

    for (const [name, call] of cases) {
        it(`${name} is routed (not 404)`, async () => {
            const status = await statusOf(call);

            expect(status, `${name} hit a URL the package does not serve`).not.toBe(404);
            expect(status).toBeLessThan(500);
        });
    }
});

describe("the candidate endpoints are not spoofable on a live server", () => {
    /*
     * The app-level test for this runs in PHP. This one runs over HTTP against a
     * booted app with real middleware, because that is the only place the status
     * code and the response envelope are the real ones -- and because
     * laravel-jobs 0.3.0 served candidates' personal data here to anyone who
     * guessed a user id.
     */
    it("a user_id in the query string is not an identity", async () => {
        const res = await http.get("my-applications", { params: { user_id: 1 }, validateStatus: () => true });

        expect(res.status).toBe(401);
        expect(JSON.stringify(res.data)).not.toContain("resume_path");
    });

    it("an X-Candidate-Id header is not an identity", async () => {
        const res = await http.get("my-applications", { headers: { "X-Candidate-Id": "1" }, validateStatus: () => true });

        expect(res.status).toBe(401);
    });

    it("the 401 does not tell the caller how to spoof", async () => {
        const res = await http.get("my-applications", { validateStatus: () => true });

        expect(JSON.stringify(res.data)).not.toContain("user_id");
    });
});
