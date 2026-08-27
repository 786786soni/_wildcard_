# Pushing your GitLab repos to GitHub

Short answer: **yes.** Git is distributed, so any GitLab repo can be pushed to
GitHub with its full history — every branch, every tag, every commit. There are
three ways to do it, depending on whether you want a **one-time copy** or an
**ongoing sync**.

---

## Option 1 — `tools/gitlab-to-github-mirror.sh` (bulk, all repos at once)

The script in this directory walks every GitLab project you can read, creates
the matching repo on GitHub, and pushes the full history into it.

```bash
export GITLAB_TOKEN=glpat-xxxxxxxx    # GitLab → Settings → Access Tokens
                                      # scopes: read_api, read_repository
export GITHUB_TOKEN=ghp_xxxxxxxx      # GitHub → Settings → Developer settings → PAT
                                      # scope: repo   (+ admin:org for an org target)

./tools/gitlab-to-github-mirror.sh --dry-run    # show the plan, change nothing
./tools/gitlab-to-github-mirror.sh              # do it
```

Useful variants:

```bash
# Only one group, and keep the GitHub names short (acme/tools/cli -> cli)
./tools/gitlab-to-github-mirror.sh --group acme --strip-namespace

# Push into an organisation instead of your personal account
GITHUB_OWNER=my-org ./tools/gitlab-to-github-mirror.sh

# Keep GitLab's public/private setting instead of forcing everything private
./tools/gitlab-to-github-mirror.sh --visibility match

# Copy wikis and Git LFS objects too
./tools/gitlab-to-github-mirror.sh --include-wiki --lfs

# One-time copy, then let GitLab keep pushing to GitHub forever (see Option 2)
./tools/gitlab-to-github-mirror.sh --enable-push-mirror

# Try it on five repos first
./tools/gitlab-to-github-mirror.sh --limit 5
```

`--help` lists every flag.

**It is safe to re-run.** A GitHub repo that already has commits is skipped
unless you pass `--overwrite-existing`, and mirror clones are cached in
`WORKDIR` (default `./.gitlab-mirror`), so a second run only fetches what
changed. A run that fails partway can just be started again. Every project ends
up as a row in `$WORKDIR/report.tsv` with its outcome.

Names are mapped from GitLab's nested groups to GitHub's flat namespace:
`acme/tools/cli` → `acme-tools-cli` (or `cli` with `--strip-namespace`).
Collisions get a numeric suffix — `web-app`, `web-app-2`.

---

## Option 2 — GitLab push mirroring (keeps both sides in sync)

If you want GitLab to stay the place you push to and GitHub to follow along
automatically, use GitLab's built-in **push mirroring**. Every push to GitLab is
forwarded to GitHub within a few minutes.

Per project, in the UI:

> Settings → Repository → **Mirroring repositories**
> - Git repository URL: `https://<your-github-user>@github.com/<owner>/<repo>.git`
> - Mirror direction: **Push**
> - Password: your GitHub personal access token
> - **Mirror repository** → Save

Push mirroring is available on GitLab Free. (Pull mirroring — GitHub as the
source of truth, GitLab following — is a Premium feature.)

To turn this on for everything at once, run the script with
`--enable-push-mirror`: it configures the same thing through the GitLab API
after the first copy. Note this stores your GitHub token inside GitLab, so use
a token minted for exactly this purpose.

---

## Option 3 — GitHub's importer (one repo at a time)

<https://github.com/new/import> — paste the GitLab clone URL, give it
credentials for a private repo, and GitHub pulls the history itself. Fine for a
couple of repos, tedious for fifty, and it does not keep anything in sync.

---

## What actually moves

**Copied:** all commits, all branches, all tags, and full history. With flags,
wikis (`--include-wiki`) and LFS objects (`--lfs`).

**Not copied** — these are GitLab platform data, not git data, and no
push-based method carries them:

| GitLab | Notes |
| --- | --- |
| Issues | Need the API or a tool like `node-gitlab-2-github` |
| Merge requests | The commits arrive; the MR discussions do not. GitLab's internal `refs/merge-requests/*` are deliberately pruned so they don't clutter GitHub |
| CI/CD variables, `.gitlab-ci.yml` runs | The YAML file is copied as a file; it means nothing to GitHub Actions |
| Releases, milestones, labels, snippets | Tags are copied; GitHub Releases are not created from them |
| Members, protected branches, webhooks | Re-create on GitHub |

## Gotchas worth knowing before a big run

- **100 MB per file** is a hard GitHub limit, and pushes over ~2 GB are
  rejected. A repo with large binaries in its history will fail the push. Move
  them to LFS on the GitLab side first, or rewrite the history.
- **Secret scanning push protection** will reject a push whose history contains
  something that looks like a live credential. That is worth acting on rather
  than working around — rotate the secret.
- Everything is created **private by default**. `--visibility match` copies
  GitLab's setting; GitLab's "internal" has no GitHub equivalent and maps to
  private.
- Archived GitLab projects are skipped unless you pass `--include-archived`.
- The run is sequential and network-bound. A few hundred repos takes a while;
  start with `--dry-run` and `--limit`.
- Tokens are kept in `0600` files and passed to git through a temporary
  credential helper, so they never land in `.git/config`, in a remote URL, or
  in the process list. The temp directory is deleted on exit.
