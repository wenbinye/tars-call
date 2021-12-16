FROM registry.cn-beijing.aliyuncs.com/winwin/tars.php74base:v1.0.0
RUN apt-get update && apt-get install -y \
    curl vim-tiny iputils-ping mysql-client redis dnsutils jq \
    && rm -rf /var/lib/apt/lists/*

ENV TARS_REGISTRY=tars-tarsregistry:17890

COPY . /usr/local/server/bin/
