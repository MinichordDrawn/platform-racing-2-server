FROM golang:1.22-alpine AS build

WORKDIR /src/pr2hub_proxy
COPY pr2hub_proxy/go.mod ./
COPY pr2hub_proxy/*.go ./
RUN CGO_ENABLED=0 GOOS=linux go build -o /out/pr2hub-proxy .

FROM alpine:3.20

RUN apk add --no-cache ca-certificates

ENV PROXY_LISTEN_ADDR=:8080
ENV PROXY_CACHE_DIR=/cache
WORKDIR /app

COPY --from=build /out/pr2hub-proxy /usr/local/bin/pr2hub-proxy

# The cache directory is created here and owned by the unprivileged user, so
# the named volume mounted over it inherits that ownership rather than
# arriving owned by root.
RUN adduser -D -H -u 10001 proxy \
    && mkdir -p /cache \
    && chown -R proxy:proxy /cache

VOLUME ["/cache"]
EXPOSE 8080

# It listens on 8080 and writes only its cache, so it has no reason to be
# root.
USER proxy

CMD ["pr2hub-proxy"]
