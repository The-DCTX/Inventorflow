-- 001_totp_ldap.sql : TOTP 2FA + LDAP/LDAPS support
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret       VARCHAR(64)  NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_enabled      TINYINT(1)   NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_backup_codes TEXT         NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_source       ENUM('local','ldap') NOT NULL DEFAULT 'local';

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
  ('totp_policy',       'disabled'),
  ('ldap_enabled',      '0'),
  ('ldap_host',         ''),
  ('ldap_port',         '389'),
  ('ldap_base_dn',      ''),
  ('ldap_bind_dn',      ''),
  ('ldap_bind_pass',    ''),
  ('ldap_user_filter',  '(sAMAccountName={username})'),
  ('ldap_use_tls',      '0'),
  ('ldap_cert_verify',  '1'),
  ('ldap_cert_content', '');
