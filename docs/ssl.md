# SSL / TLS Guide

AidiPanel uses **Let's Encrypt** (via Certbot) for free SSL certificates with automatic renewal.

---

## Quick Start

```bash
# Install SSL for a domain
aidipanel ssl:install --domain example.com --email admin@example.com

# Check SSL status
aidipanel ssl:status

# Renew all certificates
aidipanel ssl:renew
```

---

## Prerequisites

Before installing SSL:

1. **Domain must point to your server** — `example.com` A record → server IP
2. **Port 80 must be open** — Let's Encrypt verifies via HTTP challenge
3. **Site must exist** — `aidipanel site:add --domain example.com` first

Check DNS:
```bash
dig +short example.com
# Should return your server's public IP
```

---

## Installing a Certificate

### Via CLI

```bash
aidipanel ssl:install --domain example.com --email admin@example.com
```

This:
1. Runs `certbot --nginx -d example.com -d www.example.com`
2. Obtains Let's Encrypt certificate
3. Updates the Nginx vhost to use the new cert
4. Reloads Nginx
5. Sets up auto-renewal

### Via Web Panel

1. Go to **SSL** in the panel
2. Select your domain
3. Enter your email
4. Click **Install Certificate**

---

## Certificate Location

Let's Encrypt certificates are stored in:

```
/etc/letsencrypt/live/example.com/
├── fullchain.pem   ← Certificate + chain (use this in Nginx)
├── privkey.pem     ← Private key
├── cert.pem        ← Certificate only
└── chain.pem       ← Intermediate chain only
```

Nginx config references:

```nginx
ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
```

---

## Auto-Renewal

AidiPanel configures automatic renewal via cron:

```cron
# /etc/cron.d/aidipanel
30 2 * * * root certbot renew --quiet --nginx >> /var/log/aidipanel-certbot.log 2>&1
```

Certbot renews certificates automatically when they are within **30 days** of expiry.

**Check renewal logs:**

```bash
tail -f /var/log/aidipanel-certbot.log
```

**Test renewal manually:**

```bash
certbot renew --dry-run
```

---

## SSL Status

```bash
aidipanel ssl:status
```

Output example:

```
  SSL Certificates
  ────────────────────────────────────────────────────────────
  example.com       Let's Encrypt   Expires: 2025-08-12  ✓ Valid
  shop.example.com  Let's Encrypt   Expires: 2025-07-30  ✓ Valid
  old.example.com   Self-signed     Expires: 2034-01-01  ⚠ Self-signed
```

---

## Self-Signed Certificate (Initial Setup)

Before you have a domain pointing to your server, AidiPanel uses a **self-signed certificate** for the panel UI itself:

```
/etc/ssl/aidipanel/aidipanel.crt
/etc/ssl/aidipanel/aidipanel.key
```

This is **only for the panel UI** on port 8443. Your user sites should use Let's Encrypt.

Your browser will show a warning for self-signed certs — this is expected. Click "Advanced" → "Proceed" to access the panel.

---

## HTTPS Redirect

AidiPanel vhosts automatically redirect HTTP → HTTPS:

```nginx
server {
    listen 80;
    server_name example.com www.example.com;
    return 301 https://$host$request_uri;
}
```

---

## SSL Security Configuration

AidiPanel configures strong SSL defaults:

```nginx
ssl_protocols       TLSv1.2 TLSv1.3;
ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:
                    ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
ssl_prefer_server_ciphers off;
ssl_session_cache   shared:SSL:10m;
ssl_session_timeout 1d;
ssl_stapling        on;
ssl_stapling_verify on;

add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
```

This configuration scores **A+** on [SSL Labs](https://www.ssllabs.com/ssltest/).

---

## Wildcard Certificates

For wildcard certs (`*.example.com`), you need DNS challenge instead of HTTP challenge:

```bash
certbot certonly \
  --manual \
  --preferred-challenges dns \
  -d example.com \
  -d "*.example.com" \
  --email admin@example.com \
  --agree-tos
```

Follow the prompts to add a DNS TXT record. Then update your Nginx vhost to use the new cert path.

---

## Multiple Domains on One Certificate

```bash
certbot --nginx \
  -d example.com \
  -d www.example.com \
  -d shop.example.com \
  --email admin@example.com
```

---

## Revoking / Deleting a Certificate

```bash
# Delete certificate for a domain
certbot delete --cert-name example.com

# List all certificates
certbot certificates
```

---

## Troubleshooting

### "Challenge failed" / Port 80 not accessible

```bash
# Check UFW allows port 80
ufw status
ufw allow 80/tcp

# Check Nginx is running and port 80 is active
systemctl status nginx
ss -tlnp | grep :80
```

### "Too many certificates" (Let's Encrypt rate limit)

Let's Encrypt allows **5 certificates per domain per week**. If you hit the limit, wait a week or use `--staging` for testing:

```bash
certbot --nginx --staging -d example.com
```

### Certificate not renewing

```bash
# Check certbot can access your domain
certbot renew --dry-run

# Check cron is running
systemctl status cron
cat /etc/cron.d/aidipanel

# Manual renewal
certbot renew --force-renewal --nginx
```

### "Name or service not known" during renewal

Your domain's DNS is not resolving. Check DNS records and TTL.
