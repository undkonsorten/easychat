#!/usr/bin/env bash
# shellcheck disable=SC2086,SC2046,SC2128,SC2178,SC2206

# Uncomment for debugging
# set -x

#
# TYPO3 extension easychat test runner based on docker/podman.
# Derived from the TYPO3BestPractices/tea runTests.sh.
#

if [ "${CI}" != "true" ]; then
    trap 'echo "runTests.sh SIGINT signal emitted";cleanUp;exit 2' SIGINT
fi

printSummary() {
    cleanUp

    echo "" >&2
    echo "###########################################################################" >&2
    echo "Result of ${TEST_SUITE}" >&2
    echo "Container runtime: ${CONTAINER_BIN}" >&2
    echo "Container suffix: ${SUFFIX}"
    echo "PHP: ${PHP_VERSION}" >&2
    echo "TYPO3: ${CORE_VERSION}" >&2
    if [[ ${TEST_SUITE} =~ ^functional$ ]]; then
        case "${DBMS}" in
            mariadb|mysql|postgres)
                echo "DBMS: ${DBMS}  version ${DBMS_VERSION}  driver ${DATABASE_DRIVER}" >&2
                ;;
            sqlite)
                echo "DBMS: ${DBMS}" >&2
                ;;
        esac
    fi
    if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
        echo "SUCCESS" >&2
    else
        echo "FAILURE" >&2
    fi
    echo "###########################################################################" >&2
    echo "" >&2
    exit ${SUITE_EXIT_CODE}
}

waitFor() {
    local HOST=${1}
    local PORT=${2}
    # 60 rather than 20 seconds: databases need noticeably longer under docker than under
    # podman to initialise a fresh data directory, and CI selects docker.
    local TESTCOMMAND="
        COUNT=0;
        while ! nc -z ${HOST} ${PORT}; do
            if [ \"\${COUNT}\" -gt 60 ]; then
              echo \"Can not connect to ${HOST} port ${PORT}. Aborting.\";
              exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} /bin/sh -c "${TESTCOMMAND}"
    # shellcheck disable=SC2181 # Disabled because we don‘t want to move the long line between the brackets
    if [[ $? -gt 0 ]]; then
        # Not "kill -SIGINT -$$": the SIGINT trap is only installed when CI is not "true", so on
        # CI the signal did nothing, the run continued and the tests connected to a database
        # that was not listening.
        cleanUp
        exit 1
    fi
}

cleanUp() {
    echo "Remove container for network \"${NETWORK}\""
    ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps --filter network=${NETWORK} --format='{{.Names}}')
    for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
        # "rm -f" rather than "kill": the database containers run with "--rm", so a signal only
        # starts their removal and the network removal below races it.
        ${CONTAINER_BIN} rm -f ${ATTACHED_CONTAINER} >/dev/null
    done
    ${CONTAINER_BIN} network rm -f ${NETWORK} >/dev/null
}

handleDbmsOptions() {
    # -a, -d, -i depend on each other. Validate input combinations and set defaults.
    case ${DBMS} in
        mariadb)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="10.11"
            if ! [[ ${DBMS_VERSION} =~ ^(10.4|10.5|10.6|10.7|10.8|10.9|10.10|10.11|11.0|11.1|11.2|11.3|11.4|11.5|11.6|11.7|11.8)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                echo >&2
                echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            ;;
        mysql)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="8.4"
            if ! [[ ${DBMS_VERSION} =~ ^(8.0|8.1|8.2|8.3|8.4)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                echo >&2
                echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            ;;
        postgres)
            if [ -n "${DATABASE_DRIVER}" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="16"
            if ! [[ ${DBMS_VERSION} =~ ^(10|11|12|13|14|15|16|17|18)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                echo >&2
                echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            ;;
        sqlite)
            if [ -n "${DATABASE_DRIVER}" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            if [ -n "${DBMS_VERSION}" ]; then
                echo "Invalid combination -d ${DBMS} -i ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
                exit 1
            fi
            ;;
        *)
            echo "Invalid option -d ${DBMS}" >&2
            echo >&2
            echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
            exit 1
            ;;
    esac
}

cleanCacheFiles() {
    echo -n "Clean caches ... "
    rm -rf \
        .Build/.cache \
        .php-cs-fixer.cache
    echo "done"
}

cleanTestFiles() {
    # test related
    echo -n "Clean test related files ... "
    rm -rf \
        .Build/public/typo3temp/var/tests/
    echo "done"
}


loadHelp() {
    # Load help text into $HELP
    read -r -d '' HELP <<EOF
EXT:easychat test runner. Execute unit, functional and other test suites in
a container based test environment. Handles execution of single test files,
sending xdebug information to a local IDE and more.

Usage: $0 [options] [file]

Options:
    -s <...>
        Specifies which script/tool to run
            - cgl: Fixes the code style with the PHP Coding Standards Fixer (PHP-CS-Fixer). Set -n for dry-run.
            - clean: clean up build, cache and testing related files and folders
            - cleanCache: clean up cache related files and folders
            - cleanTests: clean up test related files and folders
            - composer: "composer" with all remaining arguments dispatched.
            - composerNormalize: Normalizes (Set -n for dry-run) or checks the composer.json.
            - composerUpdateMax: "composer update", with no platform.php config. The suite adds
              "typo3/minimal" to a throwaway copy of the manifest, so "composer.json" stays untouched.
            - composerUpdateMin: "composer update --prefer-lowest", with platform.php set to PHP version x.x.0.
              "composer.json" stays untouched, see composerUpdateMax.
              Both also add the optional "lochmueller/index" (EXT:index) where it installs, i.e. on
              PHP 8.3+. Elsewhere the EXT:index tests are skipped.
            - fix: Runs all automatic code style fixes (composerNormalize, cgl).
            - functional: PHP functional tests. Starts a Qdrant sidecar for the re-indexing tests.
            - lintPhp: PHP linting
            - phpstan: PHPStan tests. Needs a full install with PHP 8.3+, which includes EXT:index.
            - phpstanGenerateBaseline: regenerate PHPStan baseline, handy after PHPStan updates
            - rector: Applies the Rector (typo3-rector) refactorings. Set -n for dry-run.
            - shellcheck: check runTests.sh for shell issues
            - unit (default): PHP unit tests
            - unitRandom: PHP unit tests in random order, add -o <number> to use specific seed
            - update: Updates existing typo3/core-testing-*:latest container images and removes dangling local volumes.

    -a <mysqli|pdo_mysql>
        Only with -s functional
        Specifies to use another driver, following combinations are available:
            - mysql
                - mysqli (default)
                - pdo_mysql
            - mariadb
                - mysqli (default)
                - pdo_mysql

    -b <docker|podman>
        Container environment:
            - docker
            - podman (default)

    -d <sqlite|mariadb|mysql|postgres>
        Only with -s functional
        Specifies on which DBMS tests are performed
            - mariadb: use mariadb
            - mysql: use MySQL
            - postgres: use postgres
            - sqlite: (default): use sqlite

    -i version
        Specify a specific database version
        With "-d mariadb":
            - 10.4   short-term, maintained until 2024-06-18
            - 10.5   short-term, maintained until 2025-06-24
            - 10.6   long-term, maintained until 2026-06
            - 10.7   short-term, no longer maintained
            - 10.8   short-term, maintained until 2023-05
            - 10.9   short-term, maintained until 2023-08
            - 10.10  short-term, maintained until 2023-11
            - 10.11  long-term, maintained until 2028-02 (default)
            - 11.0   development series
            - 11.1   short-term development series, maintained until 2024-08
            - 11.2   short-term development series, maintained until 2024-11
            - 11.3   short-term development series, rolling release
            - 11.4   long-term, maintained until 2029-05
            - 11.5   short-term development series, maintained until 2024-11
            - 11.6   short-term development series, maintained until 2025-02
            - 11.7   short-term development series, maintained until 2025-05
            - 11.8   long-term, maintained until 2030-06
        With "-d mysql":
            - 8.0   maintained until 2026-04 LTS
            - 8.1   unmaintained since 2023-10
            - 8.2   unmaintained since 2024-01
            - 8.3   maintained until 2024-04
            - 8.4   maintained until 2032-04 LTS (default)
        With "-d postgres":
            - 10    unmaintained since 2022-11-10
            - 11    unmaintained since 2023-11-09
            - 12    maintained until 2024-11-14
            - 13    maintained until 2025-11-13
            - 14    maintained until 2026-11-12
            - 15    maintained until 2027-11-11
            - 16    maintained until 2028-11-09 (default)
            - 17    maintained until 2029-11-08
            - 18    maintained until 2030-11-14

    -t <13.4|14.3>
        Only with -s composerUpdateMin|composerUpdateMax|phpstan|phpstanGenerateBaseline|unit|unitRandom|functional
        Specifies the TYPO3 CORE Version to be used
            - 13.4: (default) use TYPO3 v13
            - 14.3: use TYPO3 v14
        For the test suites, this selects the tests which only apply to one TYPO3 version.
        Use the version the dependencies have been installed for. A different one lets the
        tests fail with a hint about the mismatch.

    -p <8.2|8.3|8.4|8.5>
        Specifies the PHP minor version to be used
            - 8.2: use PHP 8.2
            - 8.3: (default) use PHP 8.3
            - 8.4: use PHP 8.4
            - 8.5: use PHP 8.5

    -x
        Only with -s functional|unit|unitRandom
        Send information to host instance for test or system under test break points. This is especially
        useful if a local PhpStorm instance is listening on default xdebug port 9003. A different port
        can be selected with -y

    -y <port>
        Send xdebug information to a different port than default 9003 if an IDE like PhpStorm
        is not listening on default port.

    -o <number>
        Only with -s unitRandom
        Set specific random seed to replay a random run in this order again. The phpunit randomizer
        outputs the used seed at the end (in gitlab core testing logs, too). Use that number to
        replay the unit tests in that order.

    -n
        Only with -s cgl|composerNormalize|rector
        Activate dry-run in checks so they do not actively change files and only print broken ones.

    -u
        Update existing typo3/core-testing-*:latest container images and remove dangling local volumes.
        New images are published once in a while and only the latest ones are supported by core testing.
        Use this if weird test errors occur. Also removes obsolete image versions of typo3/core-testing-*.

    -h
        Show this help.

Examples:
    # Install dependencies for TYPO3 13.4 / PHP 8.3 (needed once before running tests)
    ./Build/Scripts/runTests.sh -s composerUpdateMax

    # Run all unit tests using PHP 8.3
    ./Build/Scripts/runTests.sh
    ./Build/Scripts/runTests.sh -s unit

    # Run all unit tests and enable xdebug (have a PhpStorm listening on port 9003!)
    ./Build/Scripts/runTests.sh -x

    # Run unit tests on PHP 8.4 and filter for a test name
    ./Build/Scripts/runTests.sh -p 8.4 -- --filter HtmlToTextTest

    # Run functional tests on sqlite (default) or on another DBMS
    ./Build/Scripts/runTests.sh -s functional
    ./Build/Scripts/runTests.sh -s functional -d mariadb -i 11.4

    # Run a single functional test file on postgres
    ./Build/Scripts/runTests.sh -s functional -d postgres Tests/Functional/Indexing/QdrantReindexTest.php

    # Check code style without changing files
    ./Build/Scripts/runTests.sh -s cgl -n
EOF
}

# Functions for the individual checkers/fixers


cgl() {
     # Active dry-run for cgl needs not "-n" but specific options
     if [ -n "${CGLCHECK_DRY_RUN}" ]; then
         CGLCHECK_DRY_RUN="--dry-run --diff"
     fi
     COMMAND="php -dxdebug.mode=off .Build/bin/php-cs-fixer fix -v ${CGLCHECK_DRY_RUN} --config=Build/php-cs-fixer/config.php"
     ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-${SUFFIX} ${IMAGE_PHP} ${COMMAND}
}

composerNormalize() {
    if [ -n "${CGLCHECK_DRY_RUN}" ]; then
        CGLCHECK_DRY_RUN="--dry-run"
    fi
    COMMAND="composer normalize --no-check-lock ${CGLCHECK_DRY_RUN}"
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-normalize-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_HOME=${ROOT_DIR}/.cache/composer-home -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
}





lintPhp() {
    COMMAND="composer check:php:lint"
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lintPhp-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_HOME=${ROOT_DIR}/.cache/composer-home -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
}





rector() {
    # Active dry-run for rector needs not "-n" but "--dry-run"
    RECTOR_DRY_RUN=""
    if [ -n "${CGLCHECK_DRY_RUN}" ]; then
        RECTOR_DRY_RUN="--dry-run"
    fi
    COMMAND=(php -dxdebug.mode=off .Build/bin/rector process --config Build/rector/rector.php --no-progress-bar ${RECTOR_DRY_RUN} "$@")
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name rector-${SUFFIX} ${IMAGE_PHP} "${COMMAND[@]}"
}

phpstan() {
    PHPSTAN_CONFIG_FILE="Build/phpstan/phpstan.neon"
    COMMAND=(php -dxdebug.mode=off .Build/bin/phpstan analyse -c ${PHPSTAN_CONFIG_FILE} --no-progress --no-interaction --memory-limit 4G "$@")
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_HOME=${ROOT_DIR}/.cache/composer-home -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
}

phpstanGenerateBaseline() {
    PHPSTAN_CONFIG_FILE="Build/phpstan/phpstan.neon"
    COMMAND=(php -dxdebug.mode=off .Build/bin/phpstan analyse -c ${PHPSTAN_CONFIG_FILE} --no-progress --no-interaction --memory-limit 4G --allow-empty-baseline --generate-baseline=Build/phpstan/phpstan-baseline.neon)
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-baseline-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_HOME=${ROOT_DIR}/.cache/composer-home -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
}



# Test if at least one of the supported container binaries exists, else exit out with error
if ! type "docker" >/dev/null 2>&1 && ! type "podman" >/dev/null 2>&1; then
    echo "This script relies on docker or podman. Please install at least one of them" >&2
    exit 1
fi

# Go to the directory this script is located, so everything else is relative
# to this dir, no matter from where this script is called, then go up two dirs.
THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "$THIS_SCRIPT_DIR" || exit 1
cd ../../ || exit 1
ROOT_DIR="${PWD}"

# Option defaults
TEST_SUITE="unit"
CORE_VERSION="13.4"
DBMS="sqlite"
DBMS_VERSION=""
PHP_VERSION="8.3"
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
PHPUNIT_RANDOM=""
# CGLCHECK_DRY_RUN is a more generic dry-run switch not limited to CGL
CGLCHECK_DRY_RUN=""
DATABASE_DRIVER=""
CONTAINER_BIN=""
COMPOSER_ROOT_VERSION="0.3.x-dev"
# "composer config" and "composer require" rewrite the manifest they operate on. The install
# suites therefore run on a throwaway copy of "composer.json", selected with the "COMPOSER"
# environment variable, so that the tracked "composer.json" is never touched and the added
# "typo3/minimal" requirement can not be committed by accident.
COMPOSER_BUILD_FILE="composer.build.json"
CONTAINER_INTERACTIVE="-it --init"
HOST_UID=$(id -u)
HOST_PID=$(id -g)
USERSET=""
SUFFIX="$RANDOM"
NETWORK="easychat-${SUFFIX}"
CI_PARAMS="${CI_PARAMS:-}"
CONTAINER_HOST="host.docker.internal"

# Option parsing updates above default vars
# Reset in case getopts has been used previously in the shell
OPTIND=1
# Array for invalid options
INVALID_OPTIONS=()
# Simple option parsing based on getopts (! not getopt)
while getopts "a:b:s:d:i:p:t:xy:o:nhu" OPT; do
    case ${OPT} in
        s)
            TEST_SUITE=${OPTARG}
            ;;
        a)
            DATABASE_DRIVER=${OPTARG}
            ;;
        b)
            if ! [[ ${OPTARG} =~ ^(docker|podman)$ ]]; then
                INVALID_OPTIONS+=("-b ${OPTARG}")
            fi
            CONTAINER_BIN=${OPTARG}
            ;;
        d)
            DBMS=${OPTARG}
            ;;
        i)
            DBMS_VERSION=${OPTARG}
            ;;
        p)
            PHP_VERSION=${OPTARG}
            if ! [[ ${PHP_VERSION} =~ ^(8.2|8.3|8.4|8.5)$ ]]; then
                INVALID_OPTIONS+=("-p ${OPTARG}")
            fi
            ;;
        t)
            CORE_VERSION=${OPTARG}
            if ! [[ ${CORE_VERSION} =~ ^(13.4|14.3)$ ]]; then
                INVALID_OPTIONS+=("-t ${OPTARG}")
            fi
            ;;
        x)
            PHP_XDEBUG_ON=1
            ;;
        y)
            PHP_XDEBUG_PORT=${OPTARG}
            ;;
        o)
            PHPUNIT_RANDOM="--random-order-seed=${OPTARG}"
            ;;
        n)
            CGLCHECK_DRY_RUN="-n"
            ;;
        h)
            loadHelp
            echo "${HELP}"
            exit 0
            ;;
        u)
            TEST_SUITE=update
            ;;
        \?)
            INVALID_OPTIONS+=("-${OPTARG}")
            ;;
        :)
            INVALID_OPTIONS+=("-${OPTARG}")
            ;;
    esac
done

# Exit on invalid options
if [ ${#INVALID_OPTIONS[@]} -ne 0 ]; then
    echo "Invalid option(s):" >&2
    for I in "${INVALID_OPTIONS[@]}"; do
        echo "-"${I} >&2
    done
    echo >&2
    echo "Use \".Build/Scripts/runTests.sh -h\" to display help and valid options" >&2
    exit 1
fi

handleDbmsOptions

if [ "${CI}" == "true" ]; then
    # ENV var "CI" is set by gitlab-ci. Use it to force some CI details.
    CONTAINER_INTERACTIVE=""
elif [ ! -t 0 ] || [ ! -t 1 ]; then
    # If stdin or stdout is not a TTY (e.g. a script runner, pipe, or non-interactive shell),
    # drop the interactive "-it" flags automatically to avoid podman warning "The input device
    # is not a TTY." and docker failure, and to keep redirected output free of TTY control characters.
    # Keep "--init" so the PID 1 init process still forwards signals (e.g. ctrl-c) to the test process.
    CONTAINER_INTERACTIVE="--init"
fi

# determine default container binary to use: 1. podman 2. docker
if [[ -z "${CONTAINER_BIN}" ]]; then
    if type "podman" >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    elif type "docker" >/dev/null 2>&1; then
        CONTAINER_BIN="docker"
    fi
fi

if [ "$(uname)" != "Darwin" ] && [ "${CONTAINER_BIN}" = "docker" ]; then
    # Run docker jobs as current user to prevent permission issues. Not needed with podman.
    USERSET="--user $HOST_UID"
fi

if ! type ${CONTAINER_BIN} >/dev/null 2>&1; then
    echo "Selected container environment \"${CONTAINER_BIN}\" not found. Please install or use -b option to select one." >&2
    exit 1
fi

# Create .cache dir: composer need this.
mkdir -p .cache
mkdir -p .Build/public/typo3temp/var/tests

IMAGE_PHP="ghcr.io/typo3/core-testing-$(echo "php${PHP_VERSION}" | sed -e 's/\.//'):latest"
IMAGE_SHELLCHECK="docker.io/koalaman/shellcheck:v0.11.0"
IMAGE_QDRANT="docker.io/qdrant/qdrant:v1.19.0"
IMAGE_MARIADB="docker.io/mariadb:${DBMS_VERSION}"
IMAGE_MYSQL="docker.io/mysql:${DBMS_VERSION}"
IMAGE_POSTGRES="docker.io/postgres:${DBMS_VERSION}-alpine"

# EXT:index is an optional integration and needs PHP 8.3+. The update suites add it to the throwaway
# manifest where it installs, so the EXT:index tests run there and are skipped elsewhere.
REQUIRE_OPTIONAL_PACKAGES="typo3/minimal:^${CORE_VERSION}"
if [ "${PHP_VERSION}" != "8.2" ]; then
    REQUIRE_OPTIONAL_PACKAGES="${REQUIRE_OPTIONAL_PACKAGES} lochmueller/index:^2.3"
fi

# Remove handled options and leaving the rest in the line, so it can be passed raw to commands
shift $((OPTIND - 1))

${CONTAINER_BIN} network create ${NETWORK} >/dev/null

# In a git worktree ".git" is a file pointing to a gitdir outside "${ROOT_DIR}",
# so the mount below does not carry it and git finds no repository inside the
# container at all. Mounting the common gitdir under its original absolute path
# covers both it and the worktree gitdir nested below it.
GIT_DIR_MOUNT=""
if [ -f "${ROOT_DIR}/.git" ]; then
    GIT_COMMON_DIR="$(git -C "${ROOT_DIR}" rev-parse --git-common-dir 2>/dev/null)"
    GIT_COMMON_DIR="$(cd "${ROOT_DIR}" && cd "${GIT_COMMON_DIR}" >/dev/null 2>&1 && pwd)"
    if [ -n "${GIT_COMMON_DIR}" ] && [ "${GIT_COMMON_DIR}" != "${ROOT_DIR}" ]; then
        GIT_DIR_MOUNT="-v ${GIT_COMMON_DIR}:${GIT_COMMON_DIR}"
    fi
fi

if [ ${CONTAINER_BIN} = "docker" ]; then
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host "${CONTAINER_HOST}:host-gateway" ${USERSET} -v ${ROOT_DIR}:${ROOT_DIR} ${GIT_DIR_MOUNT} -w ${ROOT_DIR}"
    # docker creates a tmpfs owned by "root:root" which inherits the mode of its host mountpoint,
    # while "${USERSET}" passes a user but no group and runs the container as "uid=${HOST_UID}
    # gid=0". At a umask of 0022 the mountpoint comes up 0755, group 0 gets "r-x" only, and every
    # test fails with "unable to open database file". "uid" and "gid" make the mount owned by the
    # user the container runs as, "mode=1777" keeps it writable whatever the umask.
    TMPFS_MOUNT_OPTIONS="rw,noexec,nosuid,uid=${HOST_UID},gid=${HOST_PID},mode=1777"
else
    # podman
    CONTAINER_HOST="host.containers.internal"
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm --network ${NETWORK} -v ${ROOT_DIR}:${ROOT_DIR} ${GIT_DIR_MOUNT} -w ${ROOT_DIR}"
    # Rootless podman maps the container root to the host user, so the tmpfs is writable without
    # an explicit owner. "mode=1777" is kept for the rootful case.
    TMPFS_MOUNT_OPTIONS="rw,noexec,nosuid,mode=1777"
fi

if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG=" "
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=foo"
    XDEBUG_CONFIG="client_port=${PHP_XDEBUG_PORT} client_host=host.docker.internal"
fi

# Suite execution
case ${TEST_SUITE} in
    cgl)
        cgl
        SUITE_EXIT_CODE=$?
        ;;
    clean)
        cleanCacheFiles
        cleanTestFiles
        ;;
    cleanCache)
        cleanCacheFiles
        ;;
    cleanTests)
        cleanTestFiles
        ;;
    composer)
        COMMAND=(composer "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_HOME=${ROOT_DIR}/.cache/composer-home -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    composerNormalize)
        composerNormalize
        SUITE_EXIT_CODE=$?
        ;;
    composerUpdateMax)
        # .Build/vendor is wiped first: updating in place, e.g. switching between Min and Max, lets
        # phpstan/extension-installer load a phpstan.phar that composer is replacing at that moment.
        # `dumpautoload` removed due to error with missing `composer.lock` file on publishing public assets.
        COMMAND="rm -rf .Build/vendor .Build/bin && cp composer.json ${COMPOSER_BUILD_FILE} && (composer config --unset platform.php && composer require --no-ansi --no-interaction --no-progress --no-install ${REQUIRE_OPTIONAL_PACKAGES} && composer update --no-progress --no-interaction && composer show); COMPOSER_EXIT_CODE=\$?; rm -f ${COMPOSER_BUILD_FILE}; exit \$COMPOSER_EXIT_CODE"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-install-max-${SUFFIX} -e COMPOSER=${COMPOSER_BUILD_FILE} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_HOME=${ROOT_DIR}/.cache/composer-home -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    composerUpdateMin)
        # `dumpautoload` removed due to error with missing `composer.lock` file on publishing public assets.
        COMMAND="rm -rf .Build/vendor .Build/bin && cp composer.json ${COMPOSER_BUILD_FILE} && (composer config platform.php ${PHP_VERSION}.0 && composer require --no-ansi --no-interaction --no-progress --no-install ${REQUIRE_OPTIONAL_PACKAGES} && composer update --prefer-lowest --no-progress --no-interaction && composer show); COMPOSER_EXIT_CODE=\$?; rm -f ${COMPOSER_BUILD_FILE}; exit \$COMPOSER_EXIT_CODE"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-install-min-${SUFFIX} -e COMPOSER=${COMPOSER_BUILD_FILE} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_HOME=${ROOT_DIR}/.cache/composer-home -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    fix)
        composerNormalize
        SUITE_EXIT_CODE=$?
        cgl
        SUITE_EXIT_CODE=$((SUITE_EXIT_CODE + $?))
        ;;
    functional)
        EXCLUDE_GROUPS=(--exclude-group "not-${DBMS}" --exclude-group "not-core-${CORE_VERSION}")
        COMMAND=(.Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml "${EXCLUDE_GROUPS[@]}" "$@")
        # Qdrant sidecar for the re-indexing tests (Tests/Functional/Indexing/QdrantReindexTest.php).
        # Every test uses its own throwaway collection, so one in-memory instance serves the whole run.
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name qdrant-func-${SUFFIX} --network ${NETWORK} -d --tmpfs /qdrant/storage:rw,noexec,nosuid ${IMAGE_QDRANT} >/dev/null
        waitFor qdrant-func-${SUFFIX} 6333
        QDRANT_PARAMS="-e EASYCHAT_TEST_QDRANT_URL=http://qdrant-func-${SUFFIX}:6333"
        case ${DBMS} in
            mariadb)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
                waitFor mariadb-func-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${QDRANT_PARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            mysql)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mysql-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
                waitFor mysql-func-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mysql-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${QDRANT_PARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            postgres)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-func-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
                waitFor postgres-func-${SUFFIX} 5432
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=bamboo -e typo3DatabaseUsername=funcu -e typo3DatabaseHost=postgres-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${QDRANT_PARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            sqlite)
                # The functional sqlite databases are written to a tmpfs, which roughly halves the
                # runtime of the suite and leaves nothing behind on disk. The mount options differ
                # per container binary, see where "${TMPFS_MOUNT_OPTIONS}" is assigned.
                mkdir -p "${ROOT_DIR}/.Build/public/typo3temp/var/tests/functional-sqlite-dbs/"
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${ROOT_DIR}/.Build/public/typo3temp/var/tests/functional-sqlite-dbs/:${TMPFS_MOUNT_OPTIONS}"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${QDRANT_PARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
        esac
        ;;
    lintPhp)
        lintPhp
        SUITE_EXIT_CODE=$?
        ;;
    phpstan)
        phpstan "$@"
        SUITE_EXIT_CODE=$?
        ;;
    phpstanGenerateBaseline)
        phpstanGenerateBaseline "$@"
        SUITE_EXIT_CODE=$?
        ;;
    rector)
        rector "$@"
        SUITE_EXIT_CODE=$?
        ;;
    shellcheck)
        ${CONTAINER_BIN} run ${CONTAINER_INTERACTIVE} --rm --pull always ${USERSET} -v "${ROOT_DIR}":/project:ro ${IMAGE_SHELLCHECK} /project/Build/Scripts/runTests.sh
        SUITE_EXIT_CODE=$?
        ;;
    unit)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --exclude-group not-core-${CORE_VERSION} "$@"
        SUITE_EXIT_CODE=$?
        ;;
    unitRandom)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-random-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --exclude-group not-core-${CORE_VERSION} --order-by=random ${PHPUNIT_RANDOM} "$@"
        SUITE_EXIT_CODE=$?
        ;;
    update)
        # pull typo3/core-testing-*:latest versions of those ones that exist locally
        echo "> pull ghcr.io/typo3/core-testing-*:latest versions of those ones that exist locally"
        ${CONTAINER_BIN} images ghcr.io/typo3/core-testing-*:latest --format "{{.Repository}}:latest" | xargs -I {} ${CONTAINER_BIN} pull {}
        echo ""
        # remove "dangling" typo3/core-testing-* images (those tagged as <none>)
        echo "> remove \"dangling\" ghcr.io/typo3/core-testing-* images (those tagged as <none>)"
        ${CONTAINER_BIN} images --filter "reference=ghcr.io/typo3/core-testing-*" --filter "dangling=true" --format "{{.ID}}" | xargs -I {} ${CONTAINER_BIN} rmi {}
        echo ""
        ;;
    *)
        loadHelp
        echo "Invalid -s option argument ${TEST_SUITE}" >&2
        echo >&2
        echo "${HELP}" >&2
        if [ ${CONTAINER_BIN} = "docker" ]; then
            ${CONTAINER_BIN} network rm ${NETWORK} >/dev/null
        else
            ${CONTAINER_BIN} network rm -f ${NETWORK} >/dev/null
        fi
        exit 1
        ;;
esac

# Cleanup, print summary && exit with exitcode
printSummary
