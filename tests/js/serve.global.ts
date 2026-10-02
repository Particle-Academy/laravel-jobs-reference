import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

/**
 * Boots the real app on a real port, against a throwaway SQLite file.
 *
 * This exists because it IS the route-agreement check. A faked transport proves
 * the client calls what the test told it to call; only a live server proves the
 * URL it builds is a URL this package serves. A wrong path is a 404 here and
 * nothing anywhere else.
 *
 * It deliberately does not touch the dev database. A suite that migrates and seeds
 * over the database a developer is also using is a suite people learn not to run.
 *
 * ---------------------------------------------------------------------------
 * Why this writes an env FILE instead of passing DB_* in the environment
 * ---------------------------------------------------------------------------
 *
 * Because passing them does not work, and it fails in the most expensive way
 * available: silently, with a green-looking server serving the WRONG DATABASE.
 *
 * `php artisan serve` does not hand its environment to the server process. It
 * forwards a hard allow-list — `ServeCommand::$passthroughVariables`, which is
 * `APP_ENV`, `PATH`, the Herd/Xdebug ones, and nothing else. `DB_CONNECTION` and
 * `DB_DATABASE` are not on it.
 *
 * So the first version of this file migrated and seeded a temp SQLite file (those
 * are plain artisan commands and DO read the environment), then started a server
 * which read `.env` and used `database/database.sqlite`. Every request answered
 * 200 from an empty dev database, and the suite failed with "the board does not
 * list the posting" — which reads exactly like a bug in the package's `visible()`
 * scope. The scope was correct; `JobPosting::visible()->count()` was 1 the whole
 * time, against a file nothing was serving.
 *
 * `APP_ENV` *is* forwarded, and Laravel loads `.env.{APP_ENV}` when it exists. So
 * the environment is carried in a file the server will read on its own, rather
 * than in variables it has been told to drop.
 */
const PORT = Number(process.env.JOBS_LIVE_PORT ?? 8765);
const APP_ENV = "liveserve";

export const BASE_URL = `http://127.0.0.1:${PORT}`;

let child: ReturnType<typeof spawn> | undefined;
let dir: string | undefined;
let envFile: string | undefined;

/**
 * Runs an artisan command and fails LOUDLY.
 *
 * A fixture step whose failure is swallowed turns into a test failure seconds
 * later, in another file, pointing at the wrong thing.
 */
function run(args: string[], env: NodeJS.ProcessEnv): void {
    const result = spawnSync("php", args, { env, encoding: "utf8" });

    if (result.status !== 0) {
        throw new Error(`php ${args.join(" ")} failed (${result.status}):\n${result.stdout}\n${result.stderr}`);
    }
}

async function reachable(url: string): Promise<boolean> {
    try {
        const res = await fetch(url, { signal: AbortSignal.timeout(1500) });
        return res.status < 500;
    } catch {
        return false;
    }
}

export async function setup(): Promise<void> {
    dir = mkdtempSync(join(tmpdir(), "laravel-jobs-live-"));
    const database = join(dir, "live.sqlite");
    writeFileSync(database, "");

    envFile = join(process.cwd(), `.env.${APP_ENV}`);
    writeFileSync(
        envFile,
        [
            `APP_ENV=${APP_ENV}`,
            "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
            "APP_DEBUG=true",
            `APP_URL=${BASE_URL}`,
            "DB_CONNECTION=sqlite",
            `DB_DATABASE=${database}`,
            "SESSION_DRIVER=array",
            "CACHE_STORE=array",
            "QUEUE_CONNECTION=sync",
            "MAIL_MAILER=array",
            // The flag whose default this app relies on, set explicitly so the suite
            // asserts the SAFE configuration rather than inheriting whatever is
            // local. It was a vulnerability in laravel-jobs 0.3.0.
            "LARAVEL_JOBS_ALLOW_INPUT_USER_ID=false",
            "",
        ].join("\n"),
    );

    const env = { ...process.env, APP_ENV };

    run(["artisan", "migrate", "--force"], env);

    // `--class` WITHOUT a namespace on purpose. Laravel resolves a bare name under
    // `Database\Seeders`, and the fully-qualified form does not survive Windows
    // argument handling: the backslashes are stripped before PHP sees them and the
    // failure reads as a baffling
    // "Target class [DatabaseSeedersLiveServeSeeder] does not exist".
    run(["artisan", "db:seed", "--force", "--class=LiveServeSeeder"], env);

    child = spawn("php", ["artisan", "serve", `--port=${PORT}`, "--host=127.0.0.1"], {
        env,
        stdio: ["ignore", "pipe", "pipe"],
    });

    let serverLog = "";
    child.stdout?.on("data", (c) => (serverLog += String(c)));
    child.stderr?.on("data", (c) => (serverLog += String(c)));

    const deadline = Date.now() + 30_000;
    let up = false;
    while (Date.now() < deadline) {
        if (await reachable(`${BASE_URL}/api/jobs/postings`)) {
            up = true;
            break;
        }

        if (child.exitCode !== null) {
            throw new Error(`artisan serve exited with ${child.exitCode}:\n${serverLog}`);
        }

        await new Promise((r) => setTimeout(r, 250));
    }

    if (!up) {
        throw new Error(`server did not come up on ${BASE_URL} within 30s:\n${serverLog}`);
    }

    /*
     * The server answering is not the same as the server answering from the database
     * we seeded, and conflating the two cost an hour. Assert the fixture is visible
     * OVER HTTP before handing control to the tests: otherwise a server reading a
     * different `.env` serves an empty board, every test fails with "the board does
     * not list the posting", and that reads precisely like a bug in the package's
     * `visible()` scope rather than a harness that pointed at the wrong file.
     */
    const res = await fetch(`${BASE_URL}/api/jobs/postings`, { headers: { Accept: "application/json" } });
    const body = await res.text();

    if (!body.includes("live-serve-security-officer")) {
        throw new Error(
            "FIXTURE NOT VISIBLE TO THE SERVER.\n" +
                `The seeded posting is missing from ${BASE_URL}/api/jobs/postings, so the app is\n` +
                `reading a different database than the one migrate/seed wrote.\n\n` +
                `env file: ${envFile}\ndatabase:  ${database}\n\n` +
                `response: ${body.slice(0, 400)}\n\nserver log:\n${serverLog}`,
        );
    }
}

export async function teardown(): Promise<void> {
    if (child?.pid !== undefined) {
        /*
         * `artisan serve` is a wrapper: it spawns `php -S` as a GRANDCHILD. Killing
         * the wrapper on Windows leaves that one holding the port, and vitest then
         * reports "something prevents Vite servers from exiting" while the next run
         * fails to bind. `taskkill /T` takes the tree.
         */
        if (process.platform === "win32") {
            spawnSync("taskkill", ["/pid", String(child.pid), "/T", "/F"], { stdio: "ignore" });
        } else {
            child.kill("SIGTERM");
        }
    }

    if (envFile) rmSync(envFile, { force: true });
    if (dir) rmSync(dir, { recursive: true, force: true });
}
