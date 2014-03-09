# CiviCRM sync for Google Apps

This CiviCRM extension syncs the contacts in CiviCRM with the Contacts Directory in Google Apps.

Per Google's restrictions, this extension can only work with Google Apps for Business or Education accounts. If you do have a free Google Apps account, you will need to upgrade.

Please use the 'Administer / System Settings / CiviCRM sync for Google Apps' menu to configure this extension once installed.

## Forked from cividesk/sync.googleapps

Note that this codebase is forked from the very helpful CiviDesk extension. I need to do some more work to provide my changes back to them. In the meantime
I will be maintaining this fork for our own systems.

The primary difference between the upstream extension and this one is a rewrite of "queue" code to work against a configured Smart Group rather
than just all contacts in CiviCRM.

Now uses the CiviCRM smart group cache functionality to determine what
contacts to sync based on membership of a configured smart group. The
original extension synced ALL contacts which was undesirable.

