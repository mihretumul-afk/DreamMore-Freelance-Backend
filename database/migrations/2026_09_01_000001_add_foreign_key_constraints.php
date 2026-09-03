<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add all foreign key constraints that were silently ignored when tables were MyISAM.
 *
 * Now that all tables are InnoDB, these constraints will be properly enforced.
 * This ensures referential integrity across the entire database.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL-specific: information_schema queries are not available on SQLite.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // ── Profiles ───────────────────────────────────────────────────────
        $this->addConstraint('freelancer_profiles', 'user_id', 'users', 'id', 'cascade');
        $this->addConstraint('employer_profiles', 'user_id', 'users', 'id', 'cascade');

        // ── Jobs ───────────────────────────────────────────────────────────
        $this->addConstraint('marketplace_jobs', 'employer_id', 'users', 'id', 'cascade');
        $this->addConstraint('marketplace_jobs', 'category_id', 'categories', 'id', 'set null');
        $this->addConstraint('job_skills', 'job_id', 'marketplace_jobs', 'id', 'cascade');
        $this->addConstraint('job_skills', 'skill_id', 'skills', 'id', 'cascade');
        $this->addConstraint('freelancer_skills', 'freelancer_profile_id', 'freelancer_profiles', 'id', 'cascade');
        $this->addConstraint('freelancer_skills', 'skill_id', 'skills', 'id', 'cascade');
        $this->addConstraint('saved_jobs', 'user_id', 'users', 'id', 'cascade');
        $this->addConstraint('saved_jobs', 'job_id', 'marketplace_jobs', 'id', 'cascade');
        $this->addConstraint('saved_freelancers', 'user_id', 'users', 'id', 'cascade');
        $this->addConstraint('saved_freelancers', 'freelancer_profile_id', 'freelancer_profiles', 'id', 'cascade');

        // ── Proposals ──────────────────────────────────────────────────────
        $this->addConstraint('proposals', 'job_id', 'marketplace_jobs', 'id', 'cascade');
        $this->addConstraint('proposals', 'freelancer_id', 'users', 'id', 'cascade');

        // ── Contracts ──────────────────────────────────────────────────────
        $this->addConstraint('contracts', 'job_id', 'marketplace_jobs', 'id', 'restrict');
        $this->addConstraint('contracts', 'employer_id', 'users', 'id', 'restrict');
        $this->addConstraint('contracts', 'freelancer_id', 'users', 'id', 'restrict');

        // ── Milestones ─────────────────────────────────────────────────────
        $this->addConstraint('milestones', 'contract_id', 'contracts', 'id', 'restrict');
        $this->addConstraint('milestone_submissions', 'milestone_id', 'milestones', 'id', 'cascade');
        $this->addConstraint('milestone_attachments', 'milestone_id', 'milestones', 'id', 'cascade');
        $this->addConstraint('milestone_attachments', 'uploader_id', 'users', 'id', 'cascade');

        // ── Financial (RESTRICT — never CASCADE-delete financial history) ────
        $this->addConstraint('wallets', 'user_id', 'users', 'id', 'restrict');
        $this->addConstraint('payment_methods', 'user_id', 'users', 'id', 'restrict');
        $this->addConstraint('payments', 'payer_id', 'users', 'id', 'restrict');
        $this->addConstraint('payments', 'payee_id', 'users', 'id', 'set null');
        $this->addConstraint('payments', 'milestone_id', 'milestones', 'id', 'set null');
        $this->addConstraint('payments', 'payment_method_id', 'payment_methods', 'id', 'set null');
        $this->addConstraint('transactions', 'user_id', 'users', 'id', 'restrict');
        $this->addConstraint('transactions', 'payment_id', 'payments', 'id', 'set null');
        $this->addConstraint('transactions', 'wallet_id', 'wallets', 'id', 'restrict');
        $this->addConstraint('withdrawals', 'user_id', 'users', 'id', 'restrict');
        $this->addConstraint('withdrawals', 'payment_method_id', 'payment_methods', 'id', 'set null');
        $this->addConstraint('employer_budgets', 'user_id', 'users', 'id', 'restrict');


        // ── Featured Listings ──────────────────────────────────────────────
        $this->addConstraint('featured_jobs', 'job_id', 'marketplace_jobs', 'id', 'cascade');
        $this->addConstraint('featured_jobs', 'employer_id', 'users', 'id', 'cascade');
        $this->addConstraint('featured_profiles', 'user_id', 'users', 'id', 'cascade');

        // ── Messaging ──────────────────────────────────────────────────────
        $this->addConstraint('messages', 'sender_id', 'users', 'id', 'cascade');
        $this->addConstraint('messages', 'receiver_id', 'users', 'id', 'cascade');
        $this->addConstraint('messages', 'contract_id', 'contracts', 'id', 'set null');
        $this->addConstraint('notifications', 'user_id', 'users', 'id', 'cascade');

        // ── Reviews & Reports ──────────────────────────────────────────────
        $this->addConstraint('reviews', 'reviewer_id', 'users', 'id', 'cascade');
        $this->addConstraint('reviews', 'reviewee_id', 'users', 'id', 'cascade');
        $this->addConstraint('reviews', 'contract_id', 'contracts', 'id', 'cascade');
        $this->addConstraint('reports', 'reporter_id', 'users', 'id', 'cascade');
        $this->addConstraint('verifications', 'user_id', 'users', 'id', 'cascade');
        $this->addConstraint('credentials', 'user_id', 'users', 'id', 'cascade');

        // ── RBAC ───────────────────────────────────────────────────────────
        $this->addConstraint('role_permissions', 'role_id', 'roles', 'id', 'cascade');
        $this->addConstraint('role_permissions', 'permission_id', 'permissions', 'id', 'cascade');
        $this->addConstraint('admin_user_roles', 'user_id', 'users', 'id', 'cascade');
        $this->addConstraint('admin_user_roles', 'role_id', 'roles', 'id', 'cascade');

        // ── Audit Logs ─────────────────────────────────────────────────────
        $this->addConstraint('audit_logs', 'actor_id', 'users', 'id', 'set null');

        // ── Portfolio ──────────────────────────────────────────────────────
        $this->addConstraint('portfolio_items', 'user_id', 'users', 'id', 'cascade');
    }

    public function down(): void
    {
        $this->dropConstraints();
    }

    /**
     * Add a foreign key constraint if it doesn't already exist.
     */
    private function addConstraint(
        string $table,
        string $column,
        string $referencesTable,
        string $referencesColumn,
        string $onDelete
    ): void {
        // Skip if either table doesn't exist
        $tablesExist = DB::select(
            'SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)',
            [$table, $referencesTable]
        );
        if ($tablesExist[0]->cnt < 2) {
            return;
        }

        $constraintName = "fk_{$table}_{$column}";

        $exists = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );

        if (empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraintName}` FOREIGN KEY (`{$column}`) REFERENCES `{$referencesTable}` (`{$referencesColumn}`) ON DELETE {$onDelete}");
        }
    }

    /**
     * Drop all foreign key constraints added by this migration.
     */
    private function dropConstraints(): void
    {
        $tables = [
            'freelancer_profiles', 'employer_profiles',
            'marketplace_jobs', 'job_skills', 'freelancer_skills',
            'saved_jobs', 'saved_freelancers',
            'proposals', 'contracts', 'milestones',
            'milestone_submissions', 'milestone_attachments',
            'wallets', 'payment_methods', 'payments', 'transactions',
            'withdrawals', 'employer_budgets', 'escrow_transactions',
            'featured_jobs', 'featured_profiles',
            'messages', 'notifications',
            'reviews', 'reports', 'verifications', 'credentials',
            'role_permissions', 'admin_user_roles',
            'audit_logs', 'portfolio_items',
        ];

        foreach ($tables as $table) {
            $constraints = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table]
            );

            foreach ($constraints as $constraint) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint->CONSTRAINT_NAME}`");
            }
        }
    }
};
