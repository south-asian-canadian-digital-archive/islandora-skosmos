# islandora-skosmos

Publish Islandora taxonomy vocabularies as SKOS and browse them with [Skosmos](https://skosmos.org/).

- `skos_export/`: Drupal module serving `/skos-export/<vocabulary>` as Turtle.
- `docker-compose.skosmos.yml`: Skosmos behind Traefik at `/skosmos`, using the stack's Blazegraph.
- `scripts/sync.sh`: replaces a named graph with the current export.
- `config/config.ttl.template`: Skosmos config. Render it with `envsubst`.

## Install on an ISLE stack

1. Mount or copy `skos_export` into `web/modules/custom`, then `drush en skos_export`.
2. Configure `skos_export.settings`:

   ```yaml
   vocabularies:
     my_vocabulary:
       base_uri: /vocab/        # a path resolves against the request host
       title: 'My Vocabulary'
       language: en
       id_field: ''             # empty uses the term UUID
       match_field: field_authority_link
   ```

3. Create a quads namespace named `skosmos` in Blazegraph.
4. Render the config, set `SKOSMOS_CONFIG` to it, and add the compose file to `COMPOSE_FILE`.
5. Run `scripts/sync.sh` after vocabulary changes, from a container that can reach Drupal and Blazegraph.

Concept URIs never use term IDs. UUIDs differ if terms are recreated on another
environment, so set `id_field` before publishing URIs. Use a public URL for
`DRUPAL_URL`, because path-style namespaces resolve against the request host.
The upstream image is linux/amd64 only here, so ARM hosts run it under emulation.
