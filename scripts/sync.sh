#!/usr/bin/env bash
# Publish a Drupal vocabulary's SKOS export into a SPARQL store's named graph.
#   DRUPAL_URL=http://drupal VOCABULARY=my_vocab GRAPH=https://example.org/vocab/ scripts/sync.sh
# Run where both hosts resolve, e.g. inside the stack's network. Replaces the graph.
set -euo pipefail
: "${DRUPAL_URL:?}" "${VOCABULARY:?}" "${GRAPH:?}"
NAMESPACE="${NAMESPACE:-skosmos}"
STORE="${STORE:-http://blazegraph:8080/bigdata}"
TTL="$(mktemp)"; trap 'rm -f "$TTL"' EXIT
curl -fsSk "${DRUPAL_URL%/}/skos-export/${VOCABULARY}" -o "$TTL"
curl -fsS -X POST "$STORE/namespace/$NAMESPACE/sparql" --data-urlencode "update=CLEAR SILENT GRAPH <$GRAPH>" >/dev/null
curl -fsS -X POST "$STORE/namespace/$NAMESPACE/sparql" --url-query "context-uri=$GRAPH" \
  -H 'Content-Type: text/turtle' --data-binary @"$TTL" >/dev/null
echo "Loaded $(grep -c 'a skos:Concept ' "$TTL") concepts into <$GRAPH>"
