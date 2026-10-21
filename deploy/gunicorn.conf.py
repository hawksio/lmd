# Gunicorn settings for the LMD Finance app (Linux only).
bind = "127.0.0.1:8000"          # nginx proxies to this; never expose it directly
workers = 3                      # SQLite + WAL handles a few workers on one server
threads = 2
timeout = 60
max_requests = 1000              # recycle workers to limit memory growth
max_requests_jitter = 100
accesslog = "-"                  # to journald via systemd
errorlog = "-"
loglevel = "info"
forwarded_allow_ips = "127.0.0.1"
