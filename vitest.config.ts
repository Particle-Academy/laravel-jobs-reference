import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        include: ["tests/js/**/*.test.ts"],
        globalSetup: ["./tests/js/serve.global.ts"],
        // `php artisan serve` is SINGLE-THREADED. Parallel files would queue behind
        // each other on one PHP process and surface as timeouts that look like the
        // package hanging, so the suite runs sequentially on purpose.
        fileParallelism: false,
        testTimeout: 20000,
        hookTimeout: 60000,
    },
});
