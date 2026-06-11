# Vacation handover checklist

Personal checklist for Sebas before going on vacation, with Dennis
taking over solo for the duration. The goal: Dennis can both build the
theme **and** safely make plugin-side changes (with Claude Code) for
two weeks without anyone unblocking him.

Walk this list top to bottom. Anything not ticked is a risk.

---

## A. Access

Without these, Dennis is dead in the water.

- [ ] **GitHub: `JijOnline/skwirrel-gavilar` plugin repo** — add Dennis
      as a collaborator with **Write** permission. He needs to push
      from Claude Code sessions.
- [ ] **GitHub: theme repo** — Write permission as well, since the
      theme work is his main job.
- [ ] **Antagonist SSH on the `wijonline` account** — add Dennis's
      public SSH key + whitelist his current public IP for 365 days.
      Without this he can't `git pull` on staging.
- [ ] **Staging WordPress admin** — create an `administrator` account
      for Dennis (don't share yours). Send the credentials through a
      secure channel.
- [ ] **Skwirrel admin (read-only)** — useful if he needs to inspect
      how a specific product is set up in Skwirrel. Optional but cheap
      to add.
- [ ] **1Password / vault** — share the relevant vault so Dennis can
      find any credentials he forgets.

Don't share access to **production WP**. The whole point of the
vacation period is that nothing risky ships live.

---

## B. Local environment

Dennis should be able to build theme templates against full PIM data
without ever hitting Skwirrel.

- [ ] Dump the staging database and hand him the SQL file:
      ```bash
      ssh wijonline@s219.webhostingserver.nl
      cd /home/wijonline/domains/wijonline.nu/public_html/gavilarsync
      wp db export ~/staging-dump-$(date +%F).sql
      ```
      Transfer + share the file.
- [ ] Note that he should: (1) import the dump into his local WP,
      (2) `git clone` the plugin repo into `wp-content/plugins/`,
      (3) install + activate Polylang Free and Yoast Free, (4) **not**
      fill in Skwirrel credentials. The plugin will silently no-op
      its cron, but all the synced data is already in the dump.
- [ ] Confirm with Dennis that his stack (Local by Flywheel / DDEV /
      LocalWP / whatever) runs PHP 8.1+. The plugin's `readonly`
      properties don't parse below that.

---

## C. Knowledge transfer

The four docs in the repo cover everything; they need to be read in
order:

- [ ] [`README.md`](../README.md) — what the plugin is, install,
      configuration.
- [ ] [`docs/PROJECT-STATUS.md`](./PROJECT-STATUS.md) — locked
      decisions, API gotchas, open items, Claude Code workflow.
- [ ] [`docs/THEME-INTEGRATION.md`](./THEME-INTEGRATION.md) — data
      inventory and rendering recipes for the theme.
- [ ] [`docs/HANDOVER.md`](./HANDOVER.md) — this file (Dennis will
      know what you set up by reading it too).

Forward all four to Dennis before the walkthrough.

---

## D. Pilot run (30–60 minutes, before you leave)

Don't trust the handover until it's been exercised. Give Dennis a real
task and watch him do it end-to-end:

> Through a fresh Claude Code session, change the *"Specifications"*
> heading on the front-end fallback render to *"Productinformatie"*.
> Push the change. Pull on staging. Verify on a product page.

What this validates:

- [ ] He read and used the standard opening prompt for fresh sessions.
- [ ] GitHub push works from his machine.
- [ ] SSH to staging works (IP allowlist, key registered).
- [ ] `git pull` on the plugin folder works.
- [ ] He understands the boundary between plugin and theme code.
- [ ] He spots the placeholder render and knows how to disable it
      properly when his template lands.

If anything in that sequence breaks, fix it now.

---

## E. Scope agreement

Walk these out loud with Dennis. Get verbal acknowledgement on each:

- [ ] **Plugin changes are okay** if they're additive (new meta keys,
      new fields in the metabox, new helpers in `ProductDisplay`).
      Refactors and changes to the sync flow itself wait until you're
      back unless they're truly blocking.
- [ ] **No production deploys** during the vacation. Plugin and theme
      changes ship to staging only.
- [ ] **Locked decisions stay locked.** If something in
      `PROJECT-STATUS.md` annoys him, he flags it for when you're
      back; he doesn't undo it.
- [ ] **Skwirrel content gaps are not plugin bugs.** Empty product
      names, missing SEO, English-only ETIM labels — render what's
      there, don't work around it in the plugin.
- [ ] **Emergency contact.** Name one person at Jij Online he can
      page if production breaks (Skwirrel down, staging down, anyone
      hostage-taking the DB).

---

## F. Open communication touchpoints

Optional but nice for both sides:

- [ ] Slack / email channel where Dennis can park questions for you to
      read when you're back. Don't expect responses.
- [ ] Calendar block on the day you return: "Dennis catch-up". Even
      30 minutes so he can hand back cleanly.

---

## G. Last technical check before you leave

Run this on staging one final time so you know the baseline is green:

```bash
ssh wijonline@s219.webhostingserver.nl
cd /home/wijonline/domains/wijonline.nu/public_html/gavilarsync/wp-content/plugins/skwirrel-gavilar
git pull           # confirm nothing's been pushed you don't know about
git status         # clean
git log --oneline -5
```

Then in WP admin → *Settings → Skwirrel Sync*:

- [ ] **Test connection** is green.
- [ ] **Last synced at** is recent (within 24h).
- [ ] **Recent runs** log shows the latest run with `errors 0`.

If anything's red here, fix it before you fly.

---

## Quick FAQ for Dennis

These will land in his inbox the moment you're on the plane, predictably:

**Q: Can I add a `_pim_supplier` field to display?**
A: Yes. Sync side: add to `ProductMapper::PRODUCT_META_MAP`. Display
side: add to `ProductDisplay::fields()` and to your theme template.
Full resync after deploying.

**Q: The PIM data metabox is missing in Gutenberg.**
A: Expected — see `PROJECT-STATUS.md` decisions. With Classic Editor
or SiteOrigin Page Builder enabled it shows fine.

**Q: A product shows no description on the front-end.**
A: `_product_translations` is empty in Skwirrel. Content task, not a
plugin task.

**Q: ETIM labels are English on a Dutch page.**
A: Skwirrel's tenant doesn't have NL ETIM translations yet. Already
asked their devs. Render in English for now.

**Q: I changed a mapper but old products still have old data.**
A: Run a Full resync. See *PROJECT-STATUS.md → Working with Claude
Code → After schema or mapper changes*.

**Q: I'm not sure how Skwirrel structures field X.**
A: *Show sample product* button in the admin. Filter by a code, dump
the raw JSON. Don't guess.

**Q: Staging SSH refuses my connection.**
A: Antagonist IP whitelist expired or your IP changed. Re-add via the
`wijonline` Antagonist panel, 365-day expiry. PROJECT-STATUS.md
documents this.
