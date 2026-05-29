#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

php "$ROOT_DIR/bin/console.php" infra:doctor || true
php "$ROOT_DIR/bin/console.php" db:init --seed=1
php "$ROOT_DIR/bin/console.php" integrations:preflight --probe=0 || true

cat <<'EOF'

Local setup completed.
Next:
1. Start Apache/MySQL (XAMPP) and open /pazy/purephp/public
2. Run worker once: php bin/console.php worker:run --limit=50
3. Run smoke tests: php bin/console.php qa:smoke
EOF
