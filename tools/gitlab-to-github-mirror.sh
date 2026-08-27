#!/usr/bin/env bash
#
# gitlab-to-github-mirror.sh
#
# Bulk-copy every GitLab project you can read into GitHub, preserving full git
# history (all branches, all tags), and optionally keep them syncing afterwards
# by configuring GitLab's built-in push mirroring.
#
# Quick start:
#   export GITLAB_TOKEN=glpat-xxxxxxxx      # scopes: read_api, read_repository
#   export GITHUB_TOKEN=ghp_xxxxxxxx        # scopes: repo  (+ admin:org for orgs)
#   ./tools/gitlab-to-github-mirror.sh --dry-run     # see the plan
#   ./tools/gitlab-to-github-mirror.sh               # do it
#
# See tools/README.md for the full walkthrough and caveats.

set -euo pipefail

# ---------------------------------------------------------------- defaults ---

GITLAB_HOST="${GITLAB_HOST:-https://gitlab.com}"
GITHUB_API="${GITHUB_API:-https://api.github.com}"
GITHUB_HOST="${GITHUB_HOST:-https://github.com}"
GITHUB_OWNER="${GITHUB_OWNER:-}"          # user or org; default = token's user
OWNER_IS_ORG=0

WORKDIR="${WORKDIR:-./.gitlab-mirror}"
VISIBILITY="private"                       # private | public | match
NAME_SEP="-"                               # group/sub/proj -> group-sub-proj
STRIP_NAMESPACE=0
GROUP_FILTER=""
ONLY_RE=""
SKIP_RE=""
LIMIT=0
DRY_RUN=0
OWNED_ONLY=0
INCLUDE_ARCHIVED=0
INCLUDE_WIKI=0
WITH_LFS=0
ENABLE_PUSH_MIRROR=0
OVERWRITE_EXISTING=0

# ------------------------------------------------------------------- usage ---

usage() {
  cat <<'USAGE'
Usage: gitlab-to-github-mirror.sh [options]

Environment (required):
  GITLAB_TOKEN          GitLab PAT with scopes: read_api, read_repository
  GITHUB_TOKEN          GitHub PAT with scope: repo (classic), or a fine-grained
                        token with Contents+Administration: read & write

Environment (optional):
  GITLAB_HOST           default https://gitlab.com  (self-managed: https://git.acme.com)
  GITHUB_API            default https://api.github.com
  GITHUB_HOST           default https://github.com
  GITHUB_OWNER          target user or org; default = the GITHUB_TOKEN's own user
  WORKDIR               scratch dir for mirror clones; default ./.gitlab-mirror

Selection:
  --group PATH          only projects under this group/namespace path prefix
  --owned               only projects you own (default: everything you're a member of)
  --only REGEX          only projects whose full path matches REGEX
  --skip REGEX          skip projects whose full path matches REGEX
  --include-archived    include archived projects (default: skipped)
  --limit N             stop after N projects (useful with --dry-run)

Target naming (GitHub is flat, GitLab is nested):
  --sep CHAR            joiner for nested paths (default "-") => grp-sub-proj
  --strip-namespace     use only the project name, drop the group path
                        (collisions get a numeric suffix either way)

Behaviour:
  --visibility V        private (default) | public | match  (match = copy GitLab's;
                        GitLab "internal" maps to private)
  --include-wiki        also mirror each project's wiki repo
  --lfs                 also transfer Git LFS objects (needs git-lfs installed)
  --overwrite-existing  push into GitHub repos that already have commits.
                        DESTRUCTIVE: --mirror force-updates and deletes refs
                        that don't exist on the GitLab side. Off by default.
  --enable-push-mirror  after the first copy, configure GitLab push mirroring so
                        future GitLab pushes auto-forward to GitHub. Stores your
                        GITHUB_TOKEN inside GitLab -- use a dedicated token.
  --dry-run             list what would happen, change nothing
  -h, --help            this text
USAGE
}

# ------------------------------------------------------------------- args ----

while [[ $# -gt 0 ]]; do
  case "$1" in
    --group)              GROUP_FILTER="${2:?--group needs a value}"; shift 2 ;;
    --owned)              OWNED_ONLY=1; shift ;;
    --only)               ONLY_RE="${2:?--only needs a regex}"; shift 2 ;;
    --skip)               SKIP_RE="${2:?--skip needs a regex}"; shift 2 ;;
    --include-archived)   INCLUDE_ARCHIVED=1; shift ;;
    --limit)              LIMIT="${2:?--limit needs a number}"; shift 2 ;;
    --sep)                NAME_SEP="${2:?--sep needs a char}"; shift 2 ;;
    --strip-namespace)    STRIP_NAMESPACE=1; shift ;;
    --visibility)         VISIBILITY="${2:?--visibility needs a value}"; shift 2 ;;
    --include-wiki)       INCLUDE_WIKI=1; shift ;;
    --lfs)                WITH_LFS=1; shift ;;
    --overwrite-existing) OVERWRITE_EXISTING=1; shift ;;
    --enable-push-mirror) ENABLE_PUSH_MIRROR=1; shift ;;
    --dry-run)            DRY_RUN=1; shift ;;
    -h|--help)            usage; exit 0 ;;
    *) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

case "$VISIBILITY" in private|public|match) ;; *)
  echo "--visibility must be private, public or match" >&2; exit 2 ;;
esac

# ---------------------------------------------------------------- preflight --

die()  { echo "error: $*" >&2; exit 1; }
log()  { printf '%s\n' "$*" >&2; }
step() { printf '\n\033[1m==> %s\033[0m\n' "$*" >&2; }

for dep in git curl jq; do
  command -v "$dep" >/dev/null || die "$dep is required but not installed"
done
[[ -n "${GITLAB_TOKEN:-}" ]] || die "GITLAB_TOKEN is not set"
[[ -n "${GITHUB_TOKEN:-}" ]] || die "GITHUB_TOKEN is not set"
if (( WITH_LFS )) && ! git lfs version >/dev/null 2>&1; then
  die "--lfs given but git-lfs is not installed"
fi

mkdir -p "$WORKDIR/repos"
WORKDIR="$(cd "$WORKDIR" && pwd)"

# Secrets live in 0600 files, never in argv (ps) and never in a git remote URL.
umask 077
SECRET_DIR="$(mktemp -d "$WORKDIR/.secrets.XXXXXX")"
cleanup() { rm -rf "$SECRET_DIR"; }
trap cleanup EXIT INT TERM

GL_CURL="$SECRET_DIR/gitlab.curl"
GH_CURL="$SECRET_DIR/github.curl"
REQ_BODY="$SECRET_DIR/body.json"
CRED_FILE="$SECRET_DIR/git-credentials"

printf 'header = "PRIVATE-TOKEN: %s"\n' "$GITLAB_TOKEN" > "$GL_CURL"
{ printf 'header = "Authorization: Bearer %s"\n' "$GITHUB_TOKEN"
  printf 'header = "Accept: application/vnd.github+json"\n'
  printf 'header = "X-GitHub-Api-Version: 2022-11-28"\n'; } > "$GH_CURL"

host_of() { printf '%s' "${1#*://}" | cut -d/ -f1; }

: > "$CRED_FILE"
add_cred() { # add_cred BASE_URL USER TOKEN -- skipped for host-less URLs (file://)
  local host; host="$(host_of "$1")"
  if [[ -n "$host" ]]; then
    printf 'https://%s:%s@%s\n' "$2" "$3" "$host" >> "$CRED_FILE"
  fi
  return 0
}
add_cred "$GITLAB_HOST" oauth2         "$GITLAB_TOKEN"
add_cred "$GITHUB_HOST" x-access-token "$GITHUB_TOKEN"

# Feed git its credentials out-of-band so tokens never land in .git/config.
export GIT_CONFIG_COUNT=2
export GIT_CONFIG_KEY_0=credential.helper GIT_CONFIG_VALUE_0="store --file=$CRED_FILE"
export GIT_CONFIG_KEY_1=credential.useHttpPath GIT_CONFIG_VALUE_1=false
export GIT_TERMINAL_PROMPT=0

# --------------------------------------------------------------- http calls --

HTTP_STATUS=""
HTTP_BODY=""

_call() { # _call CONFIG METHOD URL [JSON_BODY]
  local cfg="$1" method="$2" url="$3" body="${4-}" out
  local args=(-sS --location --retry 3 --retry-delay 2 --retry-connrefused
              --config "$cfg" -X "$method" -w $'\n%{http_code}')
  if [[ -n "$body" ]]; then
    printf '%s' "$body" > "$REQ_BODY"
    args+=(-H 'Content-Type: application/json' --data-binary "@$REQ_BODY")
  fi
  out="$(curl "${args[@]}" "$url" </dev/null)" || { HTTP_STATUS=000; HTTP_BODY=""; return 0; }
  HTTP_STATUS="${out##*$'\n'}"
  HTTP_BODY="${out%$'\n'*}"
}

gl_api() { _call "$GL_CURL" "$1" "$GITLAB_HOST/api/v4$2" "${3-}"; }
gh_api() { _call "$GH_CURL" "$1" "$GITHUB_API$2"          "${3-}"; }

api_err() { jq -r '.message? // .error? // .' <<<"$HTTP_BODY" 2>/dev/null | head -3 | tr '\n' ' '; }

# ------------------------------------------------------- identify the target --

step "Checking credentials"

gl_api GET /user
[[ "$HTTP_STATUS" == 200 ]] || die "GitLab auth failed (HTTP $HTTP_STATUS): $(api_err)"
GL_USER="$(jq -r '.username' <<<"$HTTP_BODY")"
log "GitLab: $GL_USER @ $GITLAB_HOST"

gh_api GET /user
[[ "$HTTP_STATUS" == 200 ]] || die "GitHub auth failed (HTTP $HTTP_STATUS): $(api_err)"
GH_USER="$(jq -r '.login' <<<"$HTTP_BODY")"
[[ -n "$GITHUB_OWNER" ]] || GITHUB_OWNER="$GH_USER"

if [[ "$GITHUB_OWNER" != "$GH_USER" ]]; then
  gh_api GET "/orgs/$GITHUB_OWNER"
  if [[ "$HTTP_STATUS" == 200 ]]; then
    OWNER_IS_ORG=1
  else
    die "GITHUB_OWNER '$GITHUB_OWNER' is neither your user ($GH_USER) nor a visible org (HTTP $HTTP_STATUS)"
  fi
fi
log "GitHub: $GH_USER -> target owner '$GITHUB_OWNER'$( ((OWNER_IS_ORG)) && echo ' (org)')"

# ------------------------------------------------------------ list projects --

step "Listing GitLab projects"

PROJECTS="$WORKDIR/projects.tsv"
: > "$PROJECTS"

membership_param="membership=true"
(( OWNED_ONLY )) && membership_param="owned=true"
archived_param="&archived=false"
(( INCLUDE_ARCHIVED )) && archived_param=""

page=1
while :; do
  gl_api GET "/projects?${membership_param}${archived_param}&per_page=100&page=${page}&order_by=id&sort=asc"
  [[ "$HTTP_STATUS" == 200 ]] || die "listing projects failed (HTTP $HTTP_STATUS): $(api_err)"
  count="$(jq 'length' <<<"$HTTP_BODY")"
  (( count == 0 )) && break
  # Fields are joined with US (0x1f), not TAB: tab is IFS-whitespace, so bash
  # `read` would collapse runs of tabs and silently shift every column right
  # of an empty field (e.g. a project with no default branch).
  jq -r '.[] | [
      .id,
      .path_with_namespace,
      .path,
      .http_url_to_repo,
      .visibility,
      (.default_branch // ""),
      (.empty_repo   | tostring),
      (.archived     | tostring),
      ((.wiki_enabled // false) | tostring),
      ((.description // "") | gsub("[\\n\\r\\t]"; " ") | .[0:300])
    ] | map(tostring) | join("\u001f")' <<<"$HTTP_BODY" >> "$PROJECTS"
  (( count < 100 )) && break
  page=$(( page + 1 ))
done

total_found="$(wc -l < "$PROJECTS" | tr -d ' ')"
log "found $total_found project(s) you can read"

# -------------------------------------------------------------- name mapping --

sanitize() { # GitHub repo names: [A-Za-z0-9._-]
  printf '%s' "$1" | sed -e 's#[^A-Za-z0-9._/-]#-#g' -e "s#/#${NAME_SEP}#g" \
                         -e 's#-\{2,\}#-#g' -e 's#^[-.]*##' -e 's#[-.]*$##'
}

declare -A TAKEN=()
TARGET_NAME=""
# Sets the global TARGET_NAME. Deliberately not a command substitution: the
# collision map has to survive, and $( ) would run this in a subshell.
set_target_name() {
  local src="$1" base cand n=2
  if (( STRIP_NAMESPACE )); then base="$(sanitize "${src##*/}")"
  else                           base="$(sanitize "$src")"; fi
  [[ -n "$base" ]] || base="repo"
  cand="$base"
  while [[ -n "${TAKEN[${cand,,}]:-}" ]]; do cand="${base}${NAME_SEP}${n}"; n=$(( n + 1 )); done
  TAKEN["${cand,,}"]="$src"
  TARGET_NAME="$cand"
}

# --------------------------------------------------------------- per-project --

REPORT="$WORKDIR/report.tsv"
printf 'gitlab_project\tgithub_repo\tstatus\tdetail\n' > "$REPORT"
record() { printf '%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "${4-}" >> "$REPORT"; }

ok=0; skipped=0; failed=0; processed=0

prune_internal_refs() { # GitLab keeps MR/pipeline refs in the repo; don't ship them
  local dir="$1"
  # Literal-prefix patterns, NOT globs: for-each-ref's '*' does not cross '/',
  # so 'refs/merge-requests/*' would miss refs/merge-requests/1/head.
  git -C "$dir" for-each-ref --format='delete %(refname)' \
      refs/merge-requests refs/pipelines refs/keep-around \
      refs/environments refs/tmp 2>/dev/null |
    git -C "$dir" update-ref --stdin 2>/dev/null || true
}

github_repo_has_commits() { # 0 = has commits, 1 = empty or missing
  gh_api GET "/repos/$GITHUB_OWNER/$1/commits?per_page=1"
  [[ "$HTTP_STATUS" == 200 && "$(jq 'length' <<<"$HTTP_BODY" 2>/dev/null || echo 0)" != 0 ]]
}

ensure_github_repo() { # ensure_github_repo NAME VISIBILITY DESCRIPTION -> echoes created|exists
  local name="$1" vis="$2" desc="$3" body path
  gh_api GET "/repos/$GITHUB_OWNER/$name"
  if [[ "$HTTP_STATUS" == 200 ]]; then echo exists; return 0; fi
  [[ "$HTTP_STATUS" == 404 ]] || { log "  ! cannot inspect $GITHUB_OWNER/$name (HTTP $HTTP_STATUS): $(api_err)"; return 1; }

  body="$(jq -n --arg n "$name" --arg d "$desc" --argjson p \
          "$( [[ "$vis" == private ]] && echo true || echo false )" \
          '{name:$n, description:$d, private:$p, has_issues:true, has_wiki:true, auto_init:false}')"
  path="/user/repos"
  (( OWNER_IS_ORG )) && path="/orgs/$GITHUB_OWNER/repos"
  gh_api POST "$path" "$body"
  [[ "$HTTP_STATUS" == 201 ]] || { log "  ! create failed (HTTP $HTTP_STATUS): $(api_err)"; return 1; }
  echo created
}

step "Mirroring"

while IFS=$'\x1f' read -r gl_id gl_full gl_path gl_url gl_vis gl_defbr gl_empty gl_arch gl_wiki gl_desc <&3; do
  [[ -n "${gl_full:-}" ]] || continue
  [[ -z "$GROUP_FILTER" || "$gl_full" == "$GROUP_FILTER"/* ]] || continue
  [[ -z "$ONLY_RE"     || "$gl_full" =~ $ONLY_RE ]] || continue
  [[ -z "$SKIP_RE"     || ! "$gl_full" =~ $SKIP_RE ]] || continue
  (( LIMIT > 0 && processed >= LIMIT )) && break
  processed=$(( processed + 1 ))

  set_target_name "$gl_full"; name="$TARGET_NAME"
  case "$VISIBILITY" in
    private) vis=private ;;
    public)  vis=public ;;
    match)   [[ "$gl_vis" == public ]] && vis=public || vis=private ;;
  esac

  printf '\n[%d] %s  ->  %s/%s  (%s)\n' "$processed" "$gl_full" "$GITHUB_OWNER" "$name" "$vis" >&2

  if [[ "$gl_empty" == true ]]; then
    log "  - GitLab repo is empty; creating the GitHub repo only"
  fi

  if (( DRY_RUN )); then
    record "$gl_full" "$GITHUB_OWNER/$name" "dry-run" "$vis"
    skipped=$(( skipped + 1 ))
    continue
  fi

  state="$(ensure_github_repo "$name" "$vis" "$gl_desc")" || {
    record "$gl_full" "$GITHUB_OWNER/$name" "failed" "could not create repo"
    failed=$(( failed + 1 )); continue
  }
  log "  - GitHub repo $state"

  if [[ "$gl_empty" == true ]]; then
    record "$gl_full" "$GITHUB_OWNER/$name" "created-empty" ""
    ok=$(( ok + 1 )); continue
  fi

  if [[ "$state" == exists ]] && (( ! OVERWRITE_EXISTING )) && github_repo_has_commits "$name"; then
    log "  ! $GITHUB_OWNER/$name already has commits; skipping (use --overwrite-existing to force)"
    record "$gl_full" "$GITHUB_OWNER/$name" "skipped" "target not empty"
    skipped=$(( skipped + 1 )); continue
  fi

  mirror="$WORKDIR/repos/${name}.git"
  if [[ -d "$mirror" ]]; then
    log "  - refreshing existing mirror clone"
    git -C "$mirror" remote update --prune </dev/null >/dev/null 2>&1 || {
      log "  ! fetch failed"; record "$gl_full" "$GITHUB_OWNER/$name" "failed" "fetch"; failed=$(( failed + 1 )); continue; }
  else
    log "  - cloning $gl_url"
    git clone --quiet --mirror "$gl_url" "$mirror" </dev/null || {
      log "  ! clone failed"; record "$gl_full" "$GITHUB_OWNER/$name" "failed" "clone"; failed=$(( failed + 1 )); continue; }
  fi
  prune_internal_refs "$mirror"

  if (( WITH_LFS )); then
    git -C "$mirror" lfs fetch --all </dev/null >/dev/null 2>&1 || log "  ~ no LFS objects (or LFS fetch failed)"
  fi

  gh_url="$GITHUB_HOST/$GITHUB_OWNER/$name.git"
  log "  - pushing all branches and tags"
  if ! git -C "$mirror" push --quiet --mirror "$gh_url" </dev/null; then
    log "  ! push failed (large files? secret scanning? branch protection?)"
    record "$gl_full" "$GITHUB_OWNER/$name" "failed" "push"
    failed=$(( failed + 1 )); continue
  fi
  (( WITH_LFS )) && { git -C "$mirror" lfs push --all "$gh_url" </dev/null >/dev/null 2>&1 || log "  ~ LFS push skipped"; }

  if [[ -n "$gl_defbr" ]]; then
    gh_api PATCH "/repos/$GITHUB_OWNER/$name" "$(jq -n --arg b "$gl_defbr" '{default_branch:$b}')"
    [[ "$HTTP_STATUS" == 200 ]] && log "  - default branch set to $gl_defbr" \
                                || log "  ~ could not set default branch (HTTP $HTTP_STATUS)"
  fi

  if (( INCLUDE_WIKI )) && [[ "$gl_wiki" == true ]]; then
    wiki_src="${gl_url%.git}.wiki.git"
    wiki_dir="$WORKDIR/repos/${name}.wiki.git"
    if [[ -d "$wiki_dir" ]] || git clone --quiet --mirror "$wiki_src" "$wiki_dir" </dev/null 2>/dev/null; then
      prune_internal_refs "$wiki_dir"
      if git -C "$wiki_dir" push --quiet --mirror "$GITHUB_HOST/$GITHUB_OWNER/$name.wiki.git" </dev/null 2>/dev/null; then
        log "  - wiki mirrored"
      else
        log "  ~ wiki push skipped (enable the wiki on GitHub and create one page first)"
      fi
    else
      log "  ~ no wiki content"
    fi
  fi

  if (( ENABLE_PUSH_MIRROR )); then
    gl_api GET "/projects/$gl_id/remote_mirrors"
    if [[ "$HTTP_STATUS" == 200 ]] && \
       jq -e --arg u "$GITHUB_HOST/$GITHUB_OWNER/$name.git" \
          'map(select(.url | contains($u))) | length > 0' <<<"$HTTP_BODY" >/dev/null 2>&1; then
      log "  - push mirror already configured"
    else
      gh_host="$(host_of "$GITHUB_HOST")"
      if [[ -z "$gh_host" ]]; then
        log "  ~ push mirror skipped: no host in GITHUB_HOST ($GITHUB_HOST)"
      else
        mirror_url="${GITHUB_HOST%%://*}://$GH_USER:$GITHUB_TOKEN@$gh_host/$GITHUB_OWNER/$name.git"
        gl_api POST "/projects/$gl_id/remote_mirrors" \
          "$(jq -n --arg u "$mirror_url" '{url:$u, enabled:true, only_protected_branches:false, keep_divergent_refs:false}')"
        [[ "$HTTP_STATUS" == 201 ]] && log "  - push mirror enabled on GitLab" \
                                    || log "  ~ push mirror not enabled (HTTP $HTTP_STATUS): $(api_err)"
      fi
    fi
  fi

  record "$gl_full" "$GITHUB_OWNER/$name" "ok" ""
  ok=$(( ok + 1 ))
done 3< "$PROJECTS"

# ----------------------------------------------------------------- summary ---

step "Summary"
printf 'processed %d  |  ok %d  |  skipped %d  |  failed %d\n' \
  "$processed" "$ok" "$skipped" "$failed" >&2
log "report: $REPORT"
log "mirror clones kept in $WORKDIR/repos (safe to delete, or keep to resume faster)"
(( failed == 0 ))
