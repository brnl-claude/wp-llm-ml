"""Prints a release's notes: the changelog entries that are new since the commit the previous release was built from,
followed by how to install or update.

Usage: release_notes.py [--json] [previous-commit] [this-commit]. With --json only the new entries are printed, as a
JSON list. Without a previous commit (or when git can't find it), the
newest section is used; without this commit, the changelog as checked out. "## " starts a section, "- " an entry,
indented lines continue it. (Same approach as imgcraft's .github/scripts/release_notes.py.)
"""
import json
import re
import subprocess
import sys

CHANGELOG = "CHANGELOG.md"

# On GitHub, "@name" in a release's notes would mention whoever is called that, and "#7" would link to issue or pull
# request 7. As code they're only text.
REFERENCE = re.compile(r"(?<![\w`&])(@[A-Za-z0-9][A-Za-z0-9-]*|#\d+\b)")

FOOTER = """
**Installeren:** download `wp-llm-ml.zip` hieronder (niet uitpakken) → WordPress → Plugins → Nieuwe plugin →
Plugin uploaden → Nu installeren → Activeren. Probeer een nieuwe versie eerst op een testsite.

**Bijwerken:** een geïnstalleerde plugin meldt deze versie vanzelf onder Dashboard → Updates.
"""


def without_mentions(entry):
    return REFERENCE.sub(r"`\1`", entry)


def sections(text):
    result = []
    for line in text.splitlines():
        if line.startswith("## "):
            result.append([])
        elif not result:
            continue
        elif line.startswith("- "):
            result[-1].append(line[2:].strip())
        elif line.startswith("  ") and line.strip() and result[-1]:
            result[-1][-1] += " " + line.strip()
    return result


def changelog_at(commit):
    if not commit:
        return None
    try:
        return subprocess.run(
            ["git", "show", f"{commit}:{CHANGELOG}"], check=True, capture_output=True, text=True
        ).stdout
    except subprocess.CalledProcessError:
        return None


def main():
    args = [a for a in sys.argv[1:] if a != "--json"]
    as_json = "--json" in sys.argv[1:]
    this_commit = args[1] if len(args) > 1 else None
    if this_commit:
        text = changelog_at(this_commit) or ""
    else:
        with open(CHANGELOG, encoding="utf-8") as f:
            text = f.read()
    current = sections(text)
    previous = changelog_at(args[0] if args else None)
    if previous is None:
        new = current[0] if current else []
    else:
        seen = {entry for section in sections(previous) for entry in section}
        new = [entry for section in current for entry in section if entry not in seen]
    if as_json:
        # For the plugin's "what's new" notice (bin/build-zip.sh puts it in the zip).
        print(json.dumps(new, ensure_ascii=False))
        return
    if new:
        print("Nieuw:\n")
        print("\n".join(f"- {without_mentions(entry)}" for entry in new))
    else:
        print("Geen zichtbare wijzigingen.")
    print(FOOTER)


if __name__ == "__main__":
    main()
