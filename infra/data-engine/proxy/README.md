# Rifnote Data API Proxy

The Data API runs privately on:

```text
127.0.0.1:3010
```

Expose it through Apache/Webuzo as:

```text
https://data.rifnote.com
```

## DNS

Create this Cloudflare DNS record:

```text
Type: A
Name: data
Value: 163.245.208.92
Proxy: Proxied/orange cloud
```

If you use Cloudflare proxy mode, the HTTP vhost can work behind Cloudflare SSL while you arrange an origin certificate. For production, use Full Strict SSL with an origin certificate.

## Apache

Apache on the VPS is Webuzo-managed and uses:

```text
/usr/local/apps/apache2/etc/conf.d/
```

Proxy modules are already enabled on the VPS:

- `proxy_module`
- `proxy_http_module`
- `headers_module`
- `ssl_module`

Copy `apache/data-rifnote.conf` into the Apache conf.d directory, then reload Apache.

```bash
sudo cp /home/rifnoteops/rifnote-data-engine/proxy/apache/data-rifnote.conf /usr/local/apps/apache2/etc/conf.d/data-rifnote.conf
sudo /usr/local/apps/apache2/bin/apachectl configtest
sudo /usr/local/apps/apache2/bin/apachectl graceful
```

After DNS resolves, test:

```bash
curl http://data.rifnote.com/health
curl -H "Authorization: Bearer YOUR_TOKEN" https://data.rifnote.com/v1/health
```

Never expose Postgres or Redis. They should remain bound to localhost only.
