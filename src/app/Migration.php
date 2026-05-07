<?php
/**
 * Database migration runner
 *
 * @package Universally
 */

namespace Universally;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles versioned database migrations
 */
class Migration
{
    /**
     * Option key for storing completed migrations
     */
    private const COMPLETED_OPTION = 'universally_migrations_completed';

    /**
     * Run all pending migrations
     */
    public function run(): void
    {
        $completed = $this->getCompletedMigrations();
        $migrations = universally_config('migrations');

        // Sort by version
        uksort($migrations, 'version_compare');

        foreach ($migrations as $version => $callback) {
            // Skip already completed
            if (in_array($version, $completed, true)) {
                continue;
            }

            Log::debug("Running migration for version {$version}");

            if (!is_callable($callback)) {
                Log::error("Migration {$version}: Callback not callable");
                continue;
            }

            try {
                $result = call_user_func($callback);

                if ($result === true) {
                    $this->markCompleted($version);
                    Log::info("Migration {$version}: Completed successfully");
                } else {
                    Log::error("Migration {$version}: Failed");
                }
            } catch (\Exception $e) {
                Log::exception($e, "Migration {$version} failed");
            }
        }
    }

    /**
     * Get list of completed migration versions
     */
    private function getCompletedMigrations(): array
    {
        return get_option(self::COMPLETED_OPTION, []);
    }

    /**
     * Mark a migration version as completed
     */
    private function markCompleted(string $version): void
    {
        $completed = $this->getCompletedMigrations();
        $completed[] = $version;
        update_option(self::COMPLETED_OPTION, array_unique($completed));
    }
}
