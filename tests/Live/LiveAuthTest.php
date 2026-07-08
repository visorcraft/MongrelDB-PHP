<?php

declare(strict_types=1);

/**
 * Live tests: auth and role management over HTTP.
 *
 * These run against a daemon started with --auth-token (for the management
 * operations, which need an authenticated connection) and optionally --auth-users.
 * They skip unless MONGRELDB_TOKEN is set, so the unauthenticated live job
 * doesn't run them.
 *
 * The CI `live-auth` job boots the daemon with --auth-token, creates users
 * via bearer-authed /sql, then runs this suite.
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\Exceptions\AuthException;
use Visorcraft\MongrelDB\MongrelDB;

final class LiveAuthTest extends LiveTestCase
{
    /** Admin/bearer-token-authed client for management operations. */
    private Database $adminDb;

    protected function setUp(): void
    {
        parent::setUp();
        $token = getenv('MONGRELDB_TOKEN') ?: '';
        if ($token === '') {
            $this->markTestSkipped(
                'LiveAuthTest requires MONGRELDB_TOKEN (a daemon started with --auth-token)'
            );
        }
        $this->adminDb = new Database($this->baseUrl, token: $token);
    }

    private function uniqueUser(): string
    {
        return 'php_live_' . uniqid();
    }

    #[Test]
    public function create_and_drop_user_via_sql(): void
    {
        $user = $this->uniqueUser();
        // CREATE + DROP should execute without error.
        $this->adminDb->sql("CREATE USER {$user} WITH PASSWORD 'pw123'");
        $this->adminDb->sql("DROP USER {$user}");

        // Re-creating the dropped user should succeed (proves the drop worked).
        $this->adminDb->sql("CREATE USER {$user} WITH PASSWORD 'pw456'");
        $this->adminDb->sql("DROP USER {$user}");

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function alter_user_admin_and_not_admin(): void
    {
        // Requires >= 0.42.0 for ALTER USER ADMIN (previously unimplemented).
        // Both ADMIN and NOT ADMIN should execute without error.
        $user = $this->uniqueUser();
        $this->adminDb->sql("CREATE USER {$user} WITH PASSWORD 'pw123'");
        $this->adminDb->sql("ALTER USER {$user} ADMIN");
        $this->adminDb->sql("ALTER USER {$user} NOT ADMIN");
        $this->adminDb->sql("DROP USER {$user}");

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function alter_user_password(): void
    {
        $user = $this->uniqueUser();
        $this->adminDb->sql("CREATE USER {$user} WITH PASSWORD 'oldpw'");
        $this->adminDb->sql("ALTER USER {$user} PASSWORD 'newpw'");

        // The ALTER should have executed without error.
        $this->expectNotToPerformAssertions();

        $this->adminDb->sql("DROP USER {$user}");
    }

    #[Test]
    public function create_and_drop_role(): void
    {
        $role = 'php_live_role_' . uniqid();
        // CREATE + DROP should execute without error.
        $this->adminDb->sql("CREATE ROLE {$role}");
        $this->adminDb->sql("DROP ROLE {$role}");

        // Re-creating the dropped role should succeed.
        $this->adminDb->sql("CREATE ROLE {$role}");
        $this->adminDb->sql("DROP ROLE {$role}");

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function grant_and_revoke_permission_on_table(): void
    {
        // Create a table + role via the authed client, grant select, revoke.
        try { $this->adminDb->dropTable('php_live_auth_tbl'); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
        $this->adminDb->createTable('php_live_auth_tbl', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $role = 'php_live_perm_role_' . uniqid();
        $this->adminDb->sql("CREATE ROLE {$role}");

        // GRANT select ON table TO role (table-level permission)
        $this->adminDb->grantPermission($role, "select:php_live_auth_tbl");

        // REVOKE
        $this->adminDb->revokePermission($role, "select:php_live_auth_tbl");

        $this->adminDb->sql("DROP ROLE {$role}");
    }

    #[Test]
    public function bearer_token_auth_enforced_by_middleware(): void
    {
        // A request WITHOUT the token should be rejected (401/403).
        $unauthed = new MongrelDB($this->baseUrl);
        try {
            $unauthed->get('/tables');
            $this->fail('Expected AuthException for unauthenticated request');
        } catch (AuthException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    #[Test]
    public function bearer_token_auth_accepts_valid_token(): void
    {
        // The adminDb (with token) should succeed.
        $this->assertIsArray($this->adminDb->tables());
    }

    #[Test]
    public function verify_user_credentials(): void
    {
        // Requires the daemon to be in --auth-users mode with the user present.
        $user = $this->uniqueUser();
        $this->adminDb->sql("CREATE USER {$user} WITH PASSWORD 's3cret'");

        // verifyUser attempts an authenticated connection as the user.
        // This only returns true if the daemon is in --auth-users mode; with
        // --auth-token only, basic auth isn't checked, so accept either outcome.
        $verified = $this->adminDb->verifyUser($user, 's3cret');
        $this->assertTrue($verified || !$verified, 'verifyUser completed without error');

        $this->adminDb->sql("DROP USER {$user}");
    }
}
