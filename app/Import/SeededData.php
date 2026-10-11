<?php

namespace App\Import;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The development data that `db:seed` adds: the made-up users and everything
 * they own. An import of real data drops it first, so that the real account
 * is not left beside a demo one that shares its exchange.
 *
 * Seed data exists only in the local and testing environments, so this does
 * nothing anywhere else: in production a user with one of these emails is a
 * real one.
 */
class SeededData
{
    /**
     * Delete the seeded users and all that belongs to them.
     *
     * @return int how many users were dropped
     */
    public function drop(): int
    {
        if (! app()->environment('local', 'testing')) {
            return 0;
        }

        // tkbtc has an admin@example.com of its own. Once the import has brought that one over it is not seed data.
        $imported = DB::table('import_ids')->where('type', Passes\UsersPass::TYPE)->where('created', true)->pluck('new_id');

        $users = User::whereIn('email', DatabaseSeeder::emails())->whereNotIn('id', $imported)->pluck('id');

        if ($users->isEmpty()) {
            return 0;
        }

        $assets = DB::table('assets')->whereIn('user_id', $users)->pluck('id');
        $transactions = DB::table('transactions')->whereIn('asset_id', $assets)->pluck('id');
        $parcels = DB::table('parcels')->whereIn('asset_id', $assets)->pluck('id');

        // Children before parents: most of these foreign keys refuse a delete that would orphan a row.
        DB::table('parcel_picks')->whereIn('transaction_id', $transactions)->orWhereIn('source_transaction_id', $transactions)->delete();
        DB::table('disposals')->whereIn('parcel_id', $parcels)->delete();
        DB::table('parcels')->whereIn('id', $parcels)->delete();
        DB::table('transactions')->whereIn('id', $transactions)->delete();
        DB::table('prices')->whereIn('asset_id', $assets)->delete();
        DB::table('assets')->whereIn('id', $assets)->delete();
        DB::table('exchange_balances')->whereIn('exchange_id', DB::table('exchanges')->whereIn('user_id', $users)->select('id'))->delete();
        DB::table('exchanges')->whereIn('user_id', $users)->delete();
        DB::table('tax_years')->whereIn('user_id', $users)->delete();
        DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $users)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users)->delete();
        DB::table('sessions')->whereIn('user_id', $users)->delete();
        DB::table('password_reset_tokens')->whereIn('email', DatabaseSeeder::emails())->delete();

        // An earlier import may have matched a tkbtc user to a seeded one: it is imported afresh now.
        DB::table('import_ids')->where('type', Passes\UsersPass::TYPE)->whereIn('new_id', $users)->delete();
        DB::table('users')->whereIn('id', $users)->delete();

        return $users->count();
    }
}
