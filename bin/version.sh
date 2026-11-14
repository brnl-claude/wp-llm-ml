#!/usr/bin/env bash
# Version helper for releases.
#
#   bin/version.sh                 print the plugin version (fails if the three places disagree)
#   bin/version.sh check 0.2.0     fail unless the plugin version is 0.2.0
#   bin/version.sh set 0.2.0       set the version in the plugin header, the
#                                  WPLLMML_VERSION constant and readme.txt
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
MAIN="$REPO/wp-content/plugins/wp-llm-ml/wp-llm-ml.php"
README="$REPO/wp-content/plugins/wp-llm-ml/readme.txt"

header=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$MAIN")
constant=$(sed -n "s/^define( 'WPLLMML_VERSION', '\([^']*\)' );/\1/p" "$MAIN")
stable=$(sed -n 's/^Stable tag:[[:space:]]*//p' "$README")

case "${1:-}" in
	set)
		new="${2:?usage: bin/version.sh set X.Y.Z}"
		[[ "$new" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Version must look like 1.2.3" >&2; exit 1; }
		sed -i.bak -e "s/^ \* Version:[[:space:]]*.*/ * Version:           $new/" \
			-e "s/^define( 'WPLLMML_VERSION', '[^']*' );/define( 'WPLLMML_VERSION', '$new' );/" "$MAIN"
		sed -i.bak -e "s/^Stable tag:[[:space:]]*.*/Stable tag: $new/" "$README"
		rm -f "$MAIN.bak" "$README.bak"
		if ! grep -q "^= $new =" "$README"; then
			sed -i.bak "s/^== Changelog ==$/== Changelog ==\n\n= $new =\n* …/" "$README" && rm -f "$README.bak"
			echo "Added a changelog entry for $new in readme.txt — fill it in."
		fi
		echo "Version set to $new"
		;;
	check)
		want="${2:?usage: bin/version.sh check X.Y.Z}"
		for v in "$header" "$constant" "$stable"; do
			if [ "$v" != "$want" ]; then
				echo "Version mismatch: header=$header constant=$constant readme=$stable, expected $want" >&2
				exit 1
			fi
		done
		grep -q "^= $want =" "$README" || { echo "readme.txt has no changelog entry '= $want ='" >&2; exit 1; }
		echo "$want"
		;;
	*)
		if [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
			echo "Version mismatch: header=$header constant=$constant readme=$stable" >&2
			exit 1
		fi
		echo "$header"
		;;
esac
