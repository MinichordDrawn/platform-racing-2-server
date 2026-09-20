# The `openjdk` repository has been withdrawn from Docker Hub -- removed, not
# merely deprecated -- so this image could not be built at all, and with it
# the database schema could not be created. eclipse-temurin is the successor.
# Java 11 rather than a newer one, because Liquibase 3.8.2 is from 2019.
FROM eclipse-temurin:11-jdk

ENV LIQUIBASE_VERSION="3.8.2" \
    LIQUIBASE_DRIVER="com.mysql.cj.jdbc.Driver" \
    LIQUIBASE_URL="" \
    LIQUIBASE_USERNAME="" \
    LIQUIBASE_PASSWORD="" \
    LIQUIBASE_CHANGELOG="liquibase.xml" \
    DRIVER_VERSION="8.0.18"

COPY docker/init-liquibase.sh /scripts/init-liquibase.sh
COPY docker/wait-for-it.sh /scripts/wait-for-it.sh

# curl is not in the base image, and both downloads below need it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# install liquibase
RUN curl -L -o /tmp/liquibase.tar.gz https://github.com/liquibase/liquibase/releases/download/v${LIQUIBASE_VERSION}/liquibase-${LIQUIBASE_VERSION}.tar.gz \
    && mkdir -p /opt/liquibase \
    && tar -xzf /tmp/liquibase.tar.gz -C /opt/liquibase \
    && chmod +x /opt/liquibase/liquibase \
    && ln -s /opt/liquibase/liquibase /usr/local/bin/

# install mysql java driver
RUN curl -L -o /tmp/mysql-connector-java.tar.gz https://dev.mysql.com/get/Downloads/Connector-J/mysql-connector-java-${DRIVER_VERSION}.tar.gz \
    && tar -xzf /tmp/mysql-connector-java.tar.gz -C /tmp \
    && cp /tmp/mysql-connector-java-${DRIVER_VERSION}/mysql-connector-java-${DRIVER_VERSION}.jar /opt/liquibase/lib/

# A one-shot migration runner reads a changelog and talks to the database. It
# has no reason to be root while doing either.
RUN useradd --create-home --uid 10001 liquibase \
    && chown -R liquibase:liquibase /opt/liquibase /scripts

USER liquibase

ENTRYPOINT ["/scripts/init-liquibase.sh"]
