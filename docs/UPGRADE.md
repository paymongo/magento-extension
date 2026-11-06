# Upgrade Guide

**PayMongo for Magento 2**

---

## Upgrading to a new release

Update the package, then let Magento apply the new release:

```bash
composer update paymongo/module-magento2-paymongo

bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

`setup:upgrade` is required after every release. It records the new module version (Magento
refuses to serve pages until it has) and applies any one-off data updates the release ships.
Your configuration, credentials, payment method settings, orders and registered webhooks are
kept.

If you installed manually under `app/code`, replace the contents of `app/code/PayMongo/Payment`
with the new release, then run the `bin/magento` commands above.

Check the [Changelog](../README.md#changelog) before upgrading. Any release that needs a step
beyond the commands above is listed below.

---

## Release notes

### 2.0.1

2.0.1 is the first release published as `paymongo/module-magento2-paymongo`. Follow the
[Install Guide](INSTALL.md) to set it up.

---

## Rolling back

Pin the previous version, then run the same commands:

```bash
composer require paymongo/module-magento2-paymongo:<previous version>
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

---

## Support

If you encounter issues during upgrade, contact **support@devteam.com** with:
- The output of `bin/magento --version`
- The contents of `var/log/payment.paymongo.log`
- Any errors from `var/log/system.log` or `var/log/exception.log`
