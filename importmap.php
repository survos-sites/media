<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'admin' => [
        'path' => './assets/admin.js',
        'entrypoint' => true,
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.12',
    ],
    '@tabler/core' => [
        'version' => '1.0.0',
    ],
    '@tabler/core/dist/css/tabler.min.css' => [
        'version' => '1.0.0',
        'type' => 'css',
    ],
    'simple-datatables' => [
        'version' => '9.2.2',
    ],
    'simple-datatables/dist/style.min.css' => [
        'version' => '9.2.2',
        'type' => 'css',
    ],
    'thumbhash' => [
        'version' => '0.1.1',
    ],
    'twig' => [
        'version' => '1.17.1',
    ],
    'locutus/php/strings/sprintf' => [
        'version' => '2.0.32',
    ],
    'locutus/php/strings/vsprintf' => [
        'version' => '2.0.32',
    ],
    'locutus/php/math/round' => [
        'version' => '2.0.32',
    ],
    'locutus/php/math/max' => [
        'version' => '2.0.32',
    ],
    'locutus/php/math/min' => [
        'version' => '2.0.32',
    ],
    'locutus/php/strings/strip_tags' => [
        'version' => '2.0.32',
    ],
    'locutus/php/datetime/strtotime' => [
        'version' => '2.0.32',
    ],
    'locutus/php/datetime/date' => [
        'version' => '2.0.32',
    ],
    'locutus/php/var/boolval' => [
        'version' => '2.0.32',
    ],
    'axios' => [
        'version' => '1.7.9',
    ],
    'fos-routing' => [
        'version' => '0.0.6',
    ],
    'perfect-scrollbar' => [
        'version' => '1.5.6',
    ],
    'perfect-scrollbar/css/perfect-scrollbar.min.css' => [
        'version' => '1.5.6',
        'type' => 'css',
    ],
    'datatables.net-plugins/i18n/en-GB.mjs' => [
        'version' => '2.2.1',
    ],
    'datatables.net-plugins/i18n/es-ES.mjs' => [
        'version' => '2.2.1',
    ],
    'datatables.net-plugins/i18n/de-DE.mjs' => [
        'version' => '2.2.1',
    ],
    'datatables.net-bs5' => [
        'version' => '2.1.6',
    ],
    'jquery' => [
        'version' => '3.7.1',
    ],
    'datatables.net' => [
        'version' => '2.1.6',
    ],
    'datatables.net-bs5/css/dataTables.bootstrap5.min.css' => [
        'version' => '2.1.6',
        'type' => 'css',
    ],
    'datatables.net-buttons-bs5' => [
        'version' => '3.2.2',
    ],
    'datatables.net-buttons' => [
        'version' => '3.2.2',
    ],
    'datatables.net-buttons-bs5/css/buttons.bootstrap5.min.css' => [
        'version' => '3.2.2',
        'type' => 'css',
    ],
    'datatables.net-responsive-bs5' => [
        'version' => '3.0.4',
    ],
    'datatables.net-responsive' => [
        'version' => '3.0.4',
    ],
    'datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css' => [
        'version' => '3.0.4',
        'type' => 'css',
    ],
    'datatables.net-scroller-bs5' => [
        'version' => '2.4.3',
    ],
    'datatables.net-scroller' => [
        'version' => '2.4.3',
    ],
    'datatables.net-scroller-bs5/css/scroller.bootstrap5.min.css' => [
        'version' => '2.4.3',
        'type' => 'css',
    ],
    'datatables.net-searchpanes-bs5' => [
        'version' => '2.3.3',
    ],
    'datatables.net-searchpanes' => [
        'version' => '2.3.3',
    ],
    'datatables.net-searchpanes-bs5/css/searchPanes.bootstrap5.min.css' => [
        'version' => '2.3.3',
        'type' => 'css',
    ],
    'datatables.net-searchbuilder-bs5' => [
        'version' => '1.8.2',
    ],
    'datatables.net-searchbuilder' => [
        'version' => '1.8.2',
    ],
    'datatables.net-searchbuilder-bs5/css/searchBuilder.bootstrap5.min.css' => [
        'version' => '1.8.2',
        'type' => 'css',
    ],
    'datatables.net-select-bs5' => [
        'version' => '2.1.0',
    ],
    'datatables.net-select' => [
        'version' => '2.1.0',
    ],
    'datatables.net-select-bs5/css/select.bootstrap5.min.css' => [
        'version' => '2.1.0',
        'type' => 'css',
    ],
    'd3-array' => [
        'version' => '3.2.4',
    ],
    'd3-axis' => [
        'version' => '3.0.0',
    ],
    'd3-brush' => [
        'version' => '3.0.0',
    ],
    'd3-chord' => [
        'version' => '3.0.1',
    ],
    'd3-color' => [
        'version' => '3.0.1',
    ],
    'd3-contour' => [
        'version' => '4.0.2',
    ],
    'd3-delaunay' => [
        'version' => '6.0.4',
    ],
    'd3-dispatch' => [
        'version' => '3.0.1',
    ],
    'd3-drag' => [
        'version' => '3.0.0',
    ],
    'd3-dsv' => [
        'version' => '3.0.1',
    ],
    'd3-ease' => [
        'version' => '3.0.1',
    ],
    'd3-fetch' => [
        'version' => '3.0.1',
    ],
    'd3-force' => [
        'version' => '3.0.0',
    ],
    'd3-format' => [
        'version' => '3.1.0',
    ],
    'd3-geo' => [
        'version' => '3.1.1',
    ],
    'd3-hierarchy' => [
        'version' => '3.1.2',
    ],
    'd3-interpolate' => [
        'version' => '3.0.1',
    ],
    'd3-path' => [
        'version' => '3.1.0',
    ],
    'd3-polygon' => [
        'version' => '3.0.1',
    ],
    'd3-quadtree' => [
        'version' => '3.0.1',
    ],
    'd3-random' => [
        'version' => '3.0.1',
    ],
    'd3-scale' => [
        'version' => '4.0.2',
    ],
    'd3-scale-chromatic' => [
        'version' => '3.1.0',
    ],
    'd3-selection' => [
        'version' => '3.0.0',
    ],
    'd3-shape' => [
        'version' => '3.2.0',
    ],
    'd3-time' => [
        'version' => '3.1.0',
    ],
    'd3-time-format' => [
        'version' => '4.1.0',
    ],
    'd3-timer' => [
        'version' => '3.0.1',
    ],
    'd3-transition' => [
        'version' => '3.0.1',
    ],
    'd3-zoom' => [
        'version' => '3.0.0',
    ],
    'internmap' => [
        'version' => '2.0.3',
    ],
    'delaunator' => [
        'version' => '5.0.0',
    ],
    'robust-predicates' => [
        'version' => '3.0.0',
    ],
    'd3-graphviz' => [
        'version' => '5.6.0',
    ],
    '@hpcc-js/wasm/graphviz' => [
        'version' => '2.20.0',
    ],
    'stimulus-attributes' => [
        'version' => '1.0.1',
    ],
    'escape-html' => [
        'version' => '1.0.3',
    ],
    'flag-icons' => [
        'version' => '7.5.0',
    ],
    'flag-icons/css/flag-icons.min.css' => [
        'version' => '7.5.0',
        'type' => 'css',
    ],
    'instantsearch.js' => [
        'version' => '4.86.1',
    ],
    '@algolia/events' => [
        'version' => '4.0.1',
    ],
    'algoliasearch-helper' => [
        'version' => '3.27.0',
    ],
    'qs' => [
        'version' => '6.14.1',
    ],
    'algoliasearch-helper/types/algoliasearch.js' => [
        'version' => '3.27.0',
    ],
    'instantsearch.js/es/widgets' => [
        'version' => '4.86.1',
    ],
    'instantsearch-ui-components' => [
        'version' => '0.16.0',
    ],
    'preact' => [
        'version' => '10.28.1',
    ],
    'hogan.js' => [
        'version' => '3.0.2',
    ],
    'htm/preact' => [
        'version' => '3.1.1',
    ],
    'preact/hooks' => [
        'version' => '10.28.1',
    ],
    '@babel/runtime/helpers/extends' => [
        'version' => '7.28.4',
    ],
    '@babel/runtime/helpers/defineProperty' => [
        'version' => '7.28.4',
    ],
    '@babel/runtime/helpers/objectWithoutProperties' => [
        'version' => '7.28.4',
    ],
    'htm' => [
        'version' => '3.1.1',
    ],
    'instantsearch.css/themes/algolia.min.css' => [
        'version' => '8.5.1',
        'type' => 'css',
    ],
    '@meilisearch/instant-meilisearch' => [
        'version' => '0.29.0',
    ],
    'meilisearch' => [
        'version' => '0.54.0',
    ],
    '@stimulus-components/dialog' => [
        'version' => '1.0.1',
    ],
    '@andypf/json-viewer' => [
        'version' => '2.2.0',
    ],
    'pretty-print-json' => [
        'version' => '3.0.5',
    ],
    'pretty-print-json/dist/css/pretty-print-json.min.css' => [
        'version' => '3.0.5',
        'type' => 'css',
    ],
    'debug' => [
        'version' => '4.4.3',
    ],
    'ms' => [
        'version' => '2.1.3',
    ],
    'bootstrap' => [
        'version' => '5.3.8',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    'bootstrap/dist/css/bootstrap.min.css' => [
        'version' => '5.3.8',
        'type' => 'css',
    ],
    'side-channel' => [
        'version' => '1.1.0',
    ],
    'es-errors/type' => [
        'version' => '1.3.0',
    ],
    'object-inspect' => [
        'version' => '1.13.3',
    ],
    'side-channel-list' => [
        'version' => '1.0.0',
    ],
    'side-channel-map' => [
        'version' => '1.0.1',
    ],
    'side-channel-weakmap' => [
        'version' => '1.0.2',
    ],
    'get-intrinsic' => [
        'version' => '1.2.5',
    ],
    'call-bound' => [
        'version' => '1.0.2',
    ],
    'es-errors' => [
        'version' => '1.3.0',
    ],
    'es-errors/eval' => [
        'version' => '1.3.0',
    ],
    'es-errors/range' => [
        'version' => '1.3.0',
    ],
    'es-errors/ref' => [
        'version' => '1.3.0',
    ],
    'es-errors/syntax' => [
        'version' => '1.3.0',
    ],
    'es-errors/uri' => [
        'version' => '1.3.0',
    ],
    'gopd' => [
        'version' => '1.2.0',
    ],
    'es-define-property' => [
        'version' => '1.0.1',
    ],
    'has-symbols' => [
        'version' => '1.1.0',
    ],
    'dunder-proto/get' => [
        'version' => '1.0.0',
    ],
    'call-bind-apply-helpers/functionApply' => [
        'version' => '1.0.0',
    ],
    'call-bind-apply-helpers/functionCall' => [
        'version' => '1.0.0',
    ],
    'function-bind' => [
        'version' => '1.1.2',
    ],
    'hasown' => [
        'version' => '2.0.2',
    ],
    'call-bind' => [
        'version' => '1.0.8',
    ],
    'call-bind-apply-helpers' => [
        'version' => '1.0.0',
    ],
    'set-function-length' => [
        'version' => '1.2.2',
    ],
    'call-bind-apply-helpers/applyBind' => [
        'version' => '1.0.0',
    ],
    'define-data-property' => [
        'version' => '1.1.4',
    ],
    'has-property-descriptors' => [
        'version' => '1.0.2',
    ],
    'ai' => [
        'version' => '5.0.117',
    ],
    '@babel/runtime/helpers/typeof' => [
        'version' => '7.28.4',
    ],
    '@babel/runtime/helpers/slicedToArray' => [
        'version' => '7.28.4',
    ],
    '@babel/runtime/helpers/toConsumableArray' => [
        'version' => '7.28.4',
    ],
    'markdown-to-jsx' => [
        'version' => '7.7.17',
    ],
    '@ai-sdk/gateway' => [
        'version' => '2.0.24',
    ],
    '@ai-sdk/provider-utils' => [
        'version' => '3.0.20',
    ],
    '@ai-sdk/provider' => [
        'version' => '2.0.1',
    ],
    'zod/v4' => [
        'version' => '4.2.1',
    ],
    '@opentelemetry/api' => [
        'version' => '1.9.0',
    ],
    'react' => [
        'version' => '19.2.0',
    ],
    '@vercel/oidc' => [
        'version' => '3.0.5',
    ],
    'eventsource-parser/stream' => [
        'version' => '3.0.6',
    ],
    'zod/v3' => [
        'version' => '4.2.1',
    ],
    '@standard-schema/spec' => [
        'version' => '1.1.0',
    ],
];
