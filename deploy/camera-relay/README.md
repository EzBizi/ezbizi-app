# Camera relay — for the deploy desk

Camera bridge phase 1. Nothing here is live until the desk installs it.

- **DNS:** `cams.ezbizi.com` → the box. A certificate for it (certbot).
- **Firewall:** open TCP **1936** inbound (RTMPS from bridge PCs). Nothing else new;
  8554/1935/1984/18555 stay loopback.
- **Helper:** `/opt/camera-relay/viewers.sh` (mode 755), called by MediaMTX on read/unread.
- **Binaries:** `/opt/camera-relay/mediamtx` (v1.21.2, linux_amd64) and
  `/opt/camera-relay/go2rtc` (v1.9.14, linux_amd64), plus `ffmpeg` from apt
  (the MJPEG transcode, ~10% of one core per watched camera).
- **Secret:** one random value as `CAMERA_RELAY_SECRET` in Laravel's `.env`
  and in `/etc/ezbizi/camera-relay.env` as both `CAMERA_RELAY_SECRET=<s>` and
  `MTX_AUTHHTTPADDRESS=https://app.ezbizi.com/api/v1/camera-relay/auth?secret=<s>` (https: port 80 only redirects), and in `nginx-cams.conf`
  (replace `CAMERA_RELAY_SECRET_HERE`). Then `config:cache`.
- **Laravel .env (defaults shown):** `CAMERA_RELAY_PUBLISH_URL=rtmps://cams.ezbizi.com:1936`,
  `CAMERA_RELAY_PLAY_URL=https://cams.ezbizi.com`, `CAMERA_BOX_GO2RTC=http://127.0.0.1:1984`.
- **Units:** `camera-relay.service`, `camera-mjpeg.service` → enable and start.
- **nginx:** `nginx-cams.conf` as its own site. `/api/v1/camera-relay/*` must
  reach Laravel from 127.0.0.1 with `Host: app.ezbizi.com`.

Check: `curl -s -o /dev/null -w '%{http_code}' https://cams.ezbizi.com/api/stream.mjpeg?src=x_jpg&token=bad` → 401.
