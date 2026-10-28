# Selectika AI Commerce for Magento

Composer module `selectika/module-ai-commerce`, Magento module `Selectika_AiCommerce`, version 0.1.1.
The module supplies a protected catalog snapshot and the existing Selectika storefront loader,
with an optional native commerce event bridge. All features are disabled on installation.
Account linking is manual in this version. This source package is not a Marketplace listing.

This public repository is generated from Selectika's authoritative monorepo. Maintain changes in
the monorepo's `plugins/magento` directory; do not edit or release directly from this generated
repository. The distribution pipeline publishes the reviewed module snapshot here.

Description-only customers do **not** need this module. Both `direct` and `extension` modes use
the same Selectika AI review and native Magento REST OAuth writeback. Generated drafts stay in
Selectika until approved under the retailer's publication policy. Publishing writes only the
chosen description attribute and checks it with a fresh native API read. The module neither
computes descriptions nor provides another write endpoint. Selecting extension mode in Selectika
does not install it.

## Installation

Target: Magento Open Source / Adobe Commerce 2.4, with the PHP version supported by that
merchant's Magento release. The Composer range is not a tested Magento/PHP compatibility matrix.
Test installation, DI compilation and theme behavior in the merchant's staging environment first.
This repository does not contain a Magento runtime. Source-only tests cannot establish merchant
compatibility; a separate Magento build check is recorded below.

Install a tagged release indexed on Packagist. The command below requires a registered package
and an indexed release in the `0.1` series. Run from the Magento project:

```sh
composer require selectika/module-ai-commerce:^0.1
bin/magento module:enable Selectika_AiCommerce
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f en_US he_IL
bin/magento cache:clean
```

Use the merchant's deployment process and installed locales. Check published versions at
[Packagist](https://packagist.org/packages/selectika/module-ai-commerce) and the
[public release repository](https://github.com/Selectika/magento2-ai-commerce).

For local development, copy this module inside the Magento project at
`packages/selectika/module-ai-commerce` and use a path repository:

```sh
composer config repositories.selectika-ai-commerce '{"type":"path","url":"packages/selectika/module-ai-commerce","options":{"versions":{"selectika/module-ai-commerce":"0.1.1"},"symlink":false}}'
composer require selectika/module-ai-commerce:0.1.1
```

Then run the same module-enable, setup, compilation and cache commands above. This version
override identifies a local development copy; it does not publish a package.

Open **Stores → Configuration → Selectika → AI Commerce**, choose the intended **store view**,
and configure the existing Selectika retailer name and public analytics publisher ID. Enable only
the required capabilities. These public fields never receive consumer secrets or access tokens.
Use separate store-view settings where publishers/catalog language differ.

## Native description integration, with or without the module

Use **Descriptions only — Magento REST API** (`direct`) when the merchant wants AI description
updates without waiting for a Magento code release. This path needs no module, Composer command,
PHP change or webhook receiver. It does not add a catalog feed or storefront tracking. Use
**Full Selectika Magento extension** (`extension`) when the separately installed module's catalog
and storefront features are also required. Both modes use the same native description writer;
selecting extension mode does not install the module.

This guide covers Magento 2 Open Source and Adobe Commerce on-premises/PaaS native REST, not
Magento 1 or Adobe Commerce as a Cloud Service (SaaS). See Adobe's
[authentication overview](https://developer.adobe.com/commerce/webapi/get-started/authentication/).
Validate the merchant's actual version and customizations; this is not an all-version compatibility
claim. Selectika staff must first confirm deployment of the connection editor, API and publishing
worker, and access to private credential storage. This guide and the module's package release
alone do not establish that backend readiness or activate any merchant.

### 1. Prepare the target

Agree on the canonical public HTTPS Magento installation URL, an explicit store-view **Code**
(**Stores → All Stores → the intended Store View**), one product's exact SKU, and either
`description` or `short_description`. The URL must exclude `/rest`, product paths, credentials,
query parameters and fragments; retain an installation subdirectory if applicable. Use the final
host without redirects. Private/localhost destinations are rejected.

Do not assume the code is `default`; `all` and `admin` are rejected. Confirm attribute scope and
store-view inheritance with the merchant, especially for customized global attributes. A numeric
product entity ID is not automatically a SKU. Deliberately choose the parent or variant product
whose description should change, and keep its original text for the pilot.

### 2. Create and authorize the Magento integration

The merchant's Magento administrator performs these steps:

1. Open **System → Extensions → Integrations → Add New Integration**, name it
   `Selectika descriptions`, and provide a real contact email if required.
2. Leave **Callback URL** and **Identity Link URL** empty for this manually activated integration.
   If asked for **Your Password**, enter it only in Magento. Adobe documents this manual flow in
   its [integration setup instructions](https://developer.adobe.com/commerce/extensibility/starter-kit/integration/create-integration);
   installing that starter kit is not required here.
3. Under **API → Resource Access → Custom**, select **Catalog → Inventory → Products**,
   resource `Magento_Catalog::products`. Do not grant `All`.
4. Save, select **Activate**, review the permissions and select **Allow**. Securely retain the
   **Consumer Key**, **Consumer Secret**, **Access Token** and **Access Token Secret** from
   **Integration Tokens for Extensions**, then select **Done**. See Adobe's
   [activation instructions](https://experienceleague.adobe.com/en/docs/commerce-admin/systems/integrations).

The native product resource also authorizes other product operations; Magento has no
description-only field ACL for this endpoint. Selectika restricts its own update payload to the
selected description attribute. A hard field-level authorization boundary would require a
separate Magento customization. See the native
[ACL](https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Catalog/etc/acl.xml) and
[product API resources](https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Catalog/etc/webapi.xml).
The merchant owns the integration and can revoke it. Do not share the administrator password,
paste OAuth values into tickets, or reuse the separate module feed token.

### 3. Save the connection in Selectika

Selectika staff with Admin access opens the retailer's **Settings → Server → Platform connections
→ Magento**. These controls are currently staff-only. In **Magento connection**:

| Control                                                             | Value                                                                |
| ------------------------------------------------------------------- | -------------------------------------------------------------------- |
| Enable Magento connection                                           | Enable the connection; publishing is configured separately           |
| Integration                                                         | Descriptions only — Magento REST API                                 |
| Store URL                                                           | The canonical public HTTPS installation URL                          |
| Store view code                                                     | The explicit storefront code agreed above                            |
| Product SKU source                                                  | Selectika item ID (mapped product SKU), Feed parent_sku, or Feed sku |
| Description field                                                   | Description or Short description                                     |
| Consumer key / Consumer secret / Access token / Access token secret | All four values from the same active integration                     |

The SKU choices read `mId`, `sourceObj.parent_sku`, or `sourceObj.sku`, respectively. Compare the
actual feed value with Magento before selecting one. The module feed example below uses
`sourceObj.parent_sku` for parent descriptions; other feeds may differ.

Select **Save connection**, then **Test saved connection**. The test uses persisted settings and
performs an authenticated catalog read. It does not write a product or verify write access. A
configuration marked ready means settings and credentials exist, not that a live publish passed.
If saving reports a failed runtime refresh, staff must reload the retailer before publishing.

OAuth values are write-only in the UI and stored in Google Secret Manager. Retailer configuration
holds a backend-managed version reference; settings responses expose neither that reference nor
the secrets. Leave **all four fields empty** to retain saved credentials. To replace them, submit
all four values together. Empty fields do not delete credentials.

### 4. Use the existing approval and publication flow

Connection setup does not enable AI generation or publishing. Staff configures the existing
description settings at `server.productsApiControl.createDescription.writeback`: `enabled: true`,
`platform: "magento"` (lowercase) and, for the pilot, `publishMode: "pending_approval"`. Preserve
the retailer's other settings. `immediate` remains available when the merchant explicitly chooses
the existing automatic publication policy.

In the existing AI Description workflow (`/actions/ai-description`), review one approved test
product in the agreed store view. With pending approval, the draft stays in Selectika until
publication is approved. Approval while writeback is disabled can mark content reviewed locally;
it is not proof that Magento changed.

On publication, Selectika reads the exact SKU, writes only the selected custom attribute if the
content differs, and reads the same product again to compare the saved text. All three requests
use `/rest/{storeViewCode}/V1/products/{URL-encoded-SKU}`. A successful PUT alone is not acceptance;
a readback mismatch is a failure. Verify delivery status, the Magento value and the storefront in
the intended language. Check other attributes/store views against the agreed scope. Product-save
extensions and storefront caches remain part of merchant-specific validation. Expand beyond the
pilot only after acceptance; retain the old text for a deliberate restore if required.

Selectika signs each request using OAuth 1.0a **HMAC-SHA256**, a fresh nonce and UTC timestamp,
with all four credentials. It sends `Authorization: OAuth …`, not a static Bearer token. See
[Adobe OAuth authentication](https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-oauth).
Do not enable **Allow OAuth Access Tokens to be used as standalone Bearer tokens** as a workaround;
Adobe describes that legacy option as disabled by default in its
[token authentication documentation](https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-token).

### Rotation and troubleshooting

Coordinate Magento **Reauthorize** with Selectika, then replace all four current values in the
editor, save and retest. Reauthorization issues a new access token and token secret. Credential
replacement creates a new private secret version; older versions are not automatically disabled
by this implementation and require the infrastructure team's retention process. Disabling the
Selectika connection or publishing does not revoke the Magento integration or delete secrets.

| Symptom                            | Check                                                                                                             |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Selectika 403 / missing editor     | Staff Admin role and deployed Dashboard/API version                                                               |
| Save fails                         | Complete four-field replacement, valid settings and Secret Manager permissions                                    |
| Save conflict (409)                | Select Reload, review the latest connection, then reapply the intended change                                     |
| Magento 401                        | Active integration, matching credentials, reauthorization, synchronized clocks and preserved Authorization header |
| Magento 403                        | Products resource, authorization after permission changes, and WAF rules for PUT as well as GET                   |
| Magento 404 / missing product      | Installation URL, REST route, store code, exact parent/variant SKU and product assignment                         |
| Redirect, login page or HTML       | Final canonical URL, staging access gate, CDN challenge or WAF; redirects are not followed                        |
| TLS / DNS / timeout                | Valid certificate chain, public DNS and server-to-server connectivity                                             |
| Magento 400                        | Attribute availability, content constraints and custom product-save validation                                    |
| Read succeeds but no publish       | Existing writeback policy, lowercase platform value and pending approval                                          |
| PUT succeeds but readback differs  | HTML transformations, concurrent edits, scope/inheritance or API caching                                          |
| API changed but storefront did not | Store view, rendered attribute and storefront/CDN caches                                                          |

Allow the required signed GET/PUT REST traffic with narrowly scoped security rules; do not disable
site-wide WAF protection. Share timestamps, SKU, store code and sanitized errors for diagnosis,
never credentials or Authorization headers. Adobe's
[OAuth error reference](https://developer.adobe.com/commerce/webapi/get-started/authentication/oauth-errors)
can help identify signature failures. No merchant is considered connected until deployment,
credential storage, authenticated read, approved write/readback and storefront checks are verified.

## Catalog export and Selectika feed configuration

Enable catalog export for one store view and set a unique random read-only feed token. Generate
64 hexadecimal characters using `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` in a private
terminal. Set the resulting token through Magento's configuration UI; it is encrypted using the
Magento encryption key and marked sensitive for configuration export. Rotate by replacing the
Magento value and the matching Selectika feed header together. Never place the token in a URL.

Magento cron publishes a snapshot hourly at minute 17. Build the first snapshot explicitly:

```sh
bin/magento selectika:catalog:export --store=default
```

The protected GET endpoint is:

```text
https://shop.example.com/selectika/feed/catalog/store/default/
Authorization: Bearer <read-only-feed-token>
```

HTTPS is mandatory, including correct trusted-proxy HTTPS configuration in Magento. A missing,
wrong or insecure credential returns 401; missing, stale or corrupt snapshots return 503, never a
successful empty feed. Successful responses contain `results`, `next`, `snapshot_id`,
`generated_at`, `total_rows`, `total_pages`, `page`, `store_code` and `module_version`. These
authenticated fields also provide a bounded health check. Responses are private/no-store.

Start with the mapping below, then adapt language and the retailer's existing category/gender
mapping. The current generic reader requires `format: "eJsonEndpoint"`,
`factoryConvertor: "other"` and `sources[0].authentication[0]` equal to the **whole** `Bearer ...`
header value. It follows the returned `next` URLs with that same header. The initial URL must use
the queryless `/store/default/` path: the current reader appends its own `?start=...&end=...`.
These date parameters are ignored because each export is a complete snapshot.

```json
{
  "name": "REPLACE_WITH_RETAILER_NAME",
  "lang": "he",
  "factoryConvertor": "other",
  "minNumImages": 1,
  "sources": [{
    "fullUrl": "https://shop.example.com/selectika/feed/catalog/store/default/",
    "localName": "magento-default.json",
    "useLocal": false,
    "saveFeed": true,
    "format": "eJsonEndpoint",
    "additionalName": "magento-default",
    "mergeType": "eIdMerge",
    "authentication": ["Bearer REPLACE_WITH_READ_ONLY_FEED_TOKEN"]
  }],
  "fields": {
    "id": "parent_sku",
    "model": "parent_identifier",
    "title": "title",
    "description": "description",
    "pageLink": "link",
    "images": ["image_urls"],
    "price": "price",
    "salePrice": "sale_price",
    "category": ["category_path"],
    "brand": "brand",
    "fabric": "material",
    "color": "color",
    "sizes": "size",
    "simpleProductId": "id",
    "variantSku": "sku",
    "variantEntityId": "id",
    "stock": "availability",
    "inStock": {"field": "availability", "map": {"in": "in", "out": "out"}, "byQuantity": false}
  }
}
```

The feed token follows the existing authenticated-feed-source configuration visibility in
Selectika; it is not automatically moved to the private OAuth credential store. Restrict who can
read/edit retailer feed settings. Do not paste either credential type into support tickets.

Identity contract:

| Export field | Meaning | Example feed mapping |
| --- | --- | --- |
| `parent_sku` | Exact native parent SKU, standalone SKU for simple products | `fields.id` → catalog `mId`; description writeback source |
| `parent_identifier` | Parent entity ID, independent of SKU | `fields.model` |
| `sku` | Exact child/standalone SKU | `fields.variantSku` |
| `id` | Child/standalone entity ID | `fields.simpleProductId`, `fields.variantEntityId` |
| `size` | Store-view size label, `One size` when absent | `fields.sizes` |
| `color` | Parent color label, constant across that parent's rows | `fields.color` |
| `variant_color`, `color_id`, `size_id` | Exact child attribute labels/option IDs | Retained source data, no automatic parent-ID rewrite |
| `image_urls` | JSON-encoded array of product image URLs | `fields.images: ["image_urls"]` |
| `price`, `sale_price` | Decimal strings required by the generic converter | `fields.price`, `fields.salePrice` |

Use `eIdMerge` with the example's simple entity-ID variant mapping. Do not add `itemGroupId` to
that mapping: it selects a different synthetic variant format in the existing converter.
A configurable parent spanning several colors remains **one catalog entity**. Parent color must
stay constant because the existing merger groups by color before deduplicating parent IDs.
Every child size/entity/SKU is retained, but the catalog's merged images/price/color are a parent
representation, not per-color cards. Different variant prices and a color-specific widget identity
need an explicit retailer-specific mapping and matching storefront context before activation.

Exports include enabled, storefront-visible simple/configurable/virtual/downloadable products in
the store's website. Configurable children use native salability and store-view attributes.
An unsupported visible type (including bundle/grouped), a configurable without exportable
children, an empty catalog or a row over 512 KiB fails the generation and retains the prior one.
This is deliberate protection against silently replacing a catalog with an incomplete export.
Prices are native base-currency/default-customer-group catalog prices; customer-group pricing,
tax-display conversion, currency switching, staged-content preview and custom inventory plugins
require merchant validation. Category paths require the existing Selectika taxonomy mapping.

Snapshots live outside `pub/`, under `var/selectika/catalog/<storeId>`. An exclusive export lock,
temporary generation directory and atomic pointer rename prevent partial reads. Pages contain at
most 250 rows/about 2 MiB, with SHA-256 and sequential row-count validation. `next` pins the same
immutable generation even if cron publishes a replacement. Current data expires after 24 hours;
pinned generations remain usable for 48 hours and are pruned after successful exports. The reader
still accumulates the full catalog in memory, so validate its memory budget for large catalogs.
No export is computed during an HTTP feed request or checkout. Multi-node Magento deployments
must share this `var/selectika` directory between cron and web nodes with working filesystem locks
and atomic rename; local ephemeral storage on different nodes is insufficient. Cron and PHP-FPM
must share Magento's filesystem group; owned snapshot directories use setgid/group access (2770)
and files use 0660. Preserve inherited group ownership on the parent `var/selectika` path.

Do not replace Crazyline's existing feed automatically. Compare product/variant identities,
categories, images, price and stock counts in a separate pilot before changing a live source.

## Storefront, consent and native events

The Luma/Blank RequireJS adapter reuses `https://scripts.selectika.com/selectika-wc.js` and the
existing pixel. Retailer/domain/language resolution, widgets and placements remain configured in
Selectika's current script settings. Register the store host there; the Magento retailer field
does not override loader host resolution. Set the retailer selector
`script.selectors.productIdInput` to `#selectika-product-sku` for this parent-SKU catalog.
`#selectika-selected-variant-id` and `#selectika-selected-variant-sku` expose the selected child;
`selectika:product-context` carries `{parentSku, variant}`. Unknown selections clear the child.
Hyvä, headless/PWA, custom option components and nonstandard checkout flows need a theme adapter.

The default consent gate waits before loading both the loader and pixel. Wire the merchant CMP to:

```js
window.dispatchEvent(new CustomEvent('selectika:consent', {detail: {analytics: true}}));
// Or, once the Magento adapter exists:
window.selectikaMagento.setConsent(true);
// Revocation:
window.selectikaMagento.setConsent(false);
```

Call after adapter initialization (or set the documented `selectika_analytics_consent=1` consent
cookie from the CMP before initialization). The adapter writes that session cookie with Secure,
SameSite=Lax and Path=/; Magento checks it before buffering private events. Never replay actions
that happened before consent. Revocation blocks further commands and clears queued commands; it
does not erase already sent events or another independently installed analytics runtime. Remove
legacy manual/GTM Selectika embeds during migration so they cannot initialize before this gate.

Native tracking is separately opt-in. Disabling that toggle disables this module's native event
bridge; the common loader can still initialize its configured pixel for widget analytics after
consent. It emits the existing `selectikaTrack('event', name, data)`
contract for `page_view`, `view_item`, `view_cart`, `add_to_cart`, `begin_checkout`, `purchase` with
schema version 1, `source: "storefront"`, `selectika_feature: "commerce"`, stable event IDs and
parent SKU `item_id`. Variant SKUs remain separate. Add-to-cart comes from successful Magento cart
controller completion and option-aware quote matching; private cart/order data is delivered by a
store-scoped Magento customer-data section. No email, address or payment details enter events or
full-page cached HTML. Pixel delivery is asynchronous; Magento performs no remote analytics HTTP.

The purchase event represents successful browser order placement, not confirmed payment or a
refund lifecycle. There is no server-side guaranteed-delivery backstop. Section drain is once-only;
network loss/ad blocking can lose events. Deterministic order IDs and a bounded sessionStorage
cache reduce browser replay duplicates; they do not guarantee cross-device exactly-once delivery.
Disable existing **native** commerce tags before opting in. Widget-attributed events remain
separate source/feature records: reporting must distinguish native funnel facts from widget
attribution instead of adding both `add_to_cart` or `purchase` event counts together.

The package supplies CSP host allowances for Selectika scripts/connections/images. Check the
merchant's CSP report and enforcement mode, including checkout; no blanket unsafe-inline rule is
added. Retailer-specific assets may need their own narrow policy entries.

## Packaging and release versions

The canonical package name is `selectika/module-ai-commerce`; its source repository is
[Selectika/magento2-ai-commerce](https://github.com/Selectika/magento2-ai-commerce). Release
versions come from immutable semantic Git tags such as `v0.1.0` or `v0.2.0`. Source
`composer.json` intentionally omits `version` so Packagist uses the tag rather than a second
manually maintained value. Update the runtime `Model/Config.php::VERSION` with each release.
Never move a published tag to different content. The local path-repository override above is
only for testing copied source; it does not create or publish a release.

Composer archives and GitHub/VCS archives use separate filters: `archive.exclude` and
`.gitattributes` both retain runtime PHP/XML/JS/templates, Composer registration/metadata, this
README and an approved license file if one is present. Tests, example fixtures, Git metadata,
credentials, dependency directories and build artifacts are excluded. Production autoload
classmaps also exclude tests/examples. New runtime directories require an explicit packaging
allowlist update and a package-check run.

For Packagist/GitHub, use the tagged source repository with `composer.json` at its root. A ZIP
intended for an independent Composer `artifact` repository has
different requirements: its build copy needs an explicit version derived from the validated
release tag. Do not add that generated version back to VCS metadata. Package checks establish
archive contents and autoload/registration, not publishing or Magento runtime
compatibility. See [Composer versions](https://getcomposer.org/doc/04-schema.md#version) and
[Adobe package guidance](https://developer.adobe.com/commerce/php/development/package/component).

## Validation and rollout checklist

Verified build: Magento Open Source **2.4.9** (official source commit
`755e34dd689021c5165db9d35ecff74f7dc51527`), **PHP 8.5.8** and **Composer 2.10.2**. A fresh Magento
Composer dependency installation, installation of this module's 33-file package, module enablement
and all nine `setup:di:compile` stages passed. This checks one specific build, not a compatibility
matrix. No database was installed: database setup/schema upgrades, storefront behavior, live feed
requests, tracking delivery and authenticated description writes still need merchant staging tests.

The following development checks run from the Selectika monorepo checkout. Test helpers are
excluded from the installable package; merchants should use their staging checks below.

```sh
bash plugins/magento/tests/check.sh
bash plugins/magento/tests/check-package.sh
```

The real generic-converter contract test also runs from this monorepo using its installed Yarn
dependencies and pinned Node version:

```sh
NODE_OPTIONS=--max-old-space-size=8192 yarn jest --config libs/updater/jest.config.ts \
  --roots "$PWD/plugins/magento/tests" --runInBand \
  --runTestsByPath "$PWD/plugins/magento/tests/feed-consumption.test.ts"
```

That test loads the example mapping into the real `C_FormatConvertor` (`other`), `C_DbItem` and
`C_ModelCollector`; it preserves all three children across two colors plus a standalone product.

Tests cover feed authentication, failed/empty export retention, pagination and manifest
completeness, snapshot integrity/expiry/locking, same-parent cart variant matching, store-isolated
event buffering, consent, runtime replay dedupe and payload allowlisting. PHP stubs test contracts;
they do not replace Magento integration tests. XML checks verify well-formedness, not Magento XSDs.
The offline package check bounds ZIP size and entry count, checks every runtime file's hash and
path, then verifies generated Composer autoloading and module registration with a stub registrar.

Before enabling on a merchant: install and compile against its exact Magento/PHP release; build
two store-view snapshots; follow every authenticated page while publishing another snapshot;
verify bad token/HTTP/stale generation failures; compare complete feed identities and totals.
Test full-page cache across two shoppers without private HTML, consent grant/revoke, two variants
of the same parent, cart reload, checkout/order success reload, store switch and native-vs-widget
analytics. Then validate one explicitly approved description through Selectika's existing review
flow and the target store-view REST readback. Source checks alone do not establish runtime readiness.

Official references: [Magento module registration](https://developer.adobe.com/commerce/php/development/build/component-registration/),
[integration authorization](https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-oauth/),
[private content](https://developer.adobe.com/commerce/php/development/cache/page/private-content/),
[JavaScript initialization](https://developer.adobe.com/commerce/frontend-core/javascript/init/),
[CSP](https://developer.adobe.com/commerce/php/development/security/content-security-policies/).
