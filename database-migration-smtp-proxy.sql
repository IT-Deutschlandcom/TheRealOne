USE marketing_cms;

ALTER TABLE smtp_profiles
    ADD COLUMN proxy_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_smtp_profiles_proxy FOREIGN KEY (proxy_id) REFERENCES proxies(id) ON DELETE RESTRICT;
