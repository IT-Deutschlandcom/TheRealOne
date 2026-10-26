USE marketing_cms;

ALTER TABLE proxies
    ADD COLUMN protocol ENUM('socks5','http','https') NOT NULL DEFAULT 'socks5' AFTER port,
    DROP INDEX uq_proxies_host_port,
    ADD UNIQUE KEY uq_proxies_protocol_host_port (protocol, host, port);
