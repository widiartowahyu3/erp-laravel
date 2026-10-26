#!/usr/bin/env bash
set -euo pipefail

echo "==> Running MariaDB hardening initialization script..."

# Verify required environment variables
: "${DB_APP_USERNAME:?DB_APP_USERNAME is required}"
: "${DB_APP_PASSWORD:?DB_APP_PASSWORD is required}"
: "${DB_MIGRATOR_USERNAME:?DB_MIGRATOR_USERNAME is required}"
: "${DB_MIGRATOR_PASSWORD:?DB_MIGRATOR_PASSWORD is required}"

# Prepare authentication via MYSQL_PWD (never pass password in command arguments)
if [ -f /run/secrets/db_root_password ]; then
    export MYSQL_PWD="$(head -n 1 /run/secrets/db_root_password | tr -d '\r\n')"
elif [ -n "${MARIADB_ROOT_PASSWORD:-}" ]; then
    export MYSQL_PWD="${MARIADB_ROOT_PASSWORD}"
fi

mariadb_cmd() {
    mariadb -u root "$@"
}

echo "==> Creating databases: erp_trading and erp_trading_test..."
mariadb_cmd <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`erp_trading\`
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE DATABASE IF NOT EXISTS \`erp_trading_test\`
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

    -- Remove anonymous users
    DELETE FROM mysql.global_priv WHERE User = '';
    DELETE FROM mysql.user WHERE User = '';

    -- Create erp_app user (strictly DML only: SELECT, INSERT, UPDATE, DELETE)
    CREATE USER IF NOT EXISTS '${DB_APP_USERNAME}'@'%' IDENTIFIED BY '${DB_APP_PASSWORD}';
    REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${DB_APP_USERNAME}'@'%';
    GRANT SELECT, INSERT, UPDATE, DELETE ON \`erp_trading\`.* TO '${DB_APP_USERNAME}'@'%';
    GRANT SELECT, INSERT, UPDATE, DELETE ON \`erp_trading_test\`.* TO '${DB_APP_USERNAME}'@'%';

    -- Create erp_migrator user (DDL + necessary DML for migrations, NO FILE/SUPER/GRANT)
    CREATE USER IF NOT EXISTS '${DB_MIGRATOR_USERNAME}'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';
    REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${DB_MIGRATOR_USERNAME}'@'%';
    GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER,
          SELECT, INSERT, UPDATE, DELETE
          ON \`erp_trading\`.* TO '${DB_MIGRATOR_USERNAME}'@'%';
    GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER,
          SELECT, INSERT, UPDATE, DELETE
          ON \`erp_trading_test\`.* TO '${DB_MIGRATOR_USERNAME}'@'%';

    FLUSH PRIVILEGES;
EOSQL

echo "==> MariaDB hardening initialization script completed successfully."
