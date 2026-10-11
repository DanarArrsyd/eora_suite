# PgBouncer for local development and CI (doc 25 §7, D-B1-09): transaction pooling with protocol-level prepared
# statements (max_prepared_statements). Pinned base image and distribution package; runs as an unprivileged user.
FROM alpine:3.22@sha256:5291449c3df73caf6ed85e649dec1b9e818b39a5d8c871e97afc13e9cd5e8fa8
RUN apk add --no-cache pgbouncer=1.24.0-r0 \
    && adduser -D -H -s /sbin/nologin pgbouncer-run
COPY pgbouncer.ini /etc/pgbouncer/pgbouncer.ini
COPY entrypoint.sh /entrypoint.sh
USER pgbouncer-run
EXPOSE 6432
ENTRYPOINT ["sh", "/entrypoint.sh"]
