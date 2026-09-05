#!/bin/sh
# ---------------------------------------------------------------------------
# One-shot MinIO bootstrap.
#
# Creates the media bucket the application's s3 disk expects and leaves it
# PRIVATE. That is the whole point of the exercise: book audio, page artwork and
# AI-generated media are licensed or personal content, and delivery is supposed
# to go through the API (entitlement check, then a signed URL or an
# X-Accel-Redirect). A world-readable bucket would quietly make that check
# optional, so this script never runs `mc anonymous set download`.
#
# Runs to completion and exits; compose treats a clean exit as success.
# ---------------------------------------------------------------------------
set -eu

BUCKET="${MINIO_BUCKET:-zaban-media}"

echo "minio-init: waiting for MinIO to accept connections"
until mc alias set local "http://minio:9000" "${MINIO_ROOT_USER}" "${MINIO_ROOT_PASSWORD}" >/dev/null 2>&1; do
    sleep 1
done

if mc ls "local/${BUCKET}" >/dev/null 2>&1; then
    echo "minio-init: bucket ${BUCKET} already exists"
else
    echo "minio-init: creating private bucket ${BUCKET}"
    mc mb "local/${BUCKET}"
fi

# Versioning keeps a regenerated asset from destroying the one already referenced
# by a published lesson. Cheap insurance against a bad batch regeneration.
mc version enable "local/${BUCKET}" >/dev/null 2>&1 || true

echo "minio-init: done"
