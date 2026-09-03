<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRITICAL MIGRATION: Convert all MyISAM tables to InnoDB.
 *
 * MyISAM does NOT support:
 * - Foreign key constraints (silently ignored)
 * - Transactions (DB::transaction() has no effect)
 * - Row-level locking (lockForUpdate() has no effect)
 *
 * This migration converts ALL tables to InnoDB to enable:
 * - Referential integrity enforcement
 * - Transaction support for financial operations
 * - Proper row-level locking for concurrent access
 *
 * NOTE: MySQL's default_storage_engine is set to MyISAM on this server.
 * Future migrations should explicitly specify InnoDB if this config cannot be changed.
 */
return new class extends Migration
{
    /**
     * List of all tables that need conversion.
     * Organized by category for clarity.
     */
    private const ALL_TABLES = [
        // ── Core / Auth ────────────────────────────────────────────────
        'users',
        'personal_access_tokens',
        'password_reset_tokens',
        'sessions',

        // ── RBAC ───────────────────────────────────────────────────────
        'roles',
        'permissions',
        'role_permissions',
        'admin_user_roles',

        // ── Platform Config ────────────────────────────────────────────
        'platform_settings',
        'admin_settings',
        'categories',
        'skills',

        // ── Profiles ───────────────────────────────────────────────────
        'employer_profiles',
        'freelancer_profiles',
        'freelancer_skills',
        'portfolio_items',

        // ── Jobs & Proposals ───────────────────────────────────────────
        'marketplace_jobs',
        'job_skills',
        'proposals',
        'proposal_portfolio_items',
        'saved_jobs',
        'saved_freelancers',

        // ── Contracts & Milestones ─────────────────────────────────────
        'contracts',
        'milestones',
        'milestone_submissions',
        'milestone_submission_files',
        'milestone_attachments',
        'contract_activities',

        // ── Financial ──────────────────────────────────────────────────
        'payment_methods',
        'wallets',
        'payments',
        'transactions',
        'withdrawals',
        'escrow_transactions',
        'employer_budgets',

        // ── Featured Listings (NEW) ────────────────────────────────────
        'featured_jobs',
        'featured_profiles',

        // ── Messaging & Notifications ──────────────────────────────────
        'messages',
        'notifications',

        // ── Reviews & Reports ──────────────────────────────────────────
        'reviews',
        'reports',
        'disputes',
        'verifications',
        'credentials',

        // ── Skill Tests ────────────────────────────────────────────────
        'skill_tests',
        'skill_test_questions',
        'skill_test_attempts',

        // ── Audit & Logging ────────────────────────────────────────────
        'audit_logs',
        'webhook_logs',

        // ── Queue & Cache ──────────────────────────────────────────────
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',

        // ── Laravel System ─────────────────────────────────────────────
        'migrations',
    ];

    public function up(): void
    {
        // MySQL-specific: SHOW TABLES / ALTER TABLE ENGINE are not valid on SQLite.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Get actual tables in the database (in case some haven't been created yet)
        $existingTables = DB::select('SHOW TABLES');
        $existingTableNames = array_map(fn($row) => reset($row), $existingTables);

        foreach (self::ALL_TABLES as $table) {
            if (in_array($table, $existingTableNames)) {
                DB::statement("ALTER TABLE `{$table}` ENGINE = InnoDB");
            }
        }
    }

    public function down(): void
    {
        // Rolling back would convert back to MyISAM — NOT recommended.
        // This is intentionally a no-op to prevent accidental data integrity loss.
        // If you truly need to revert, run: ALTER TABLE table_name ENGINE = MyISAM; manually.
    }
};
