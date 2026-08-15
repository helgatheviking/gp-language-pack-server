const fs = require('fs');
const path = require('path');
const readline = require('readline');

const state = process.argv[2];

// We only validate and block during the 'prepared' state of reference transaction.
if (state !== 'prepared') {
  process.exit(0);
}

// Read package.json version
const packageJsonPath = path.join(__dirname, '../package.json');
if (!fs.existsSync(packageJsonPath)) {
  process.exit(0);
}

let packageJson;
try {
  packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
} catch (e) {
  console.error('Error parsing package.json:', e.message);
  process.exit(1);
}

const packageVersion = packageJson.version;
if (!packageVersion) {
  console.error('Error: No version field found in package.json.');
  process.exit(1);
}

const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout,
  terminal: false
});

rl.on('line', (line) => {
  const parts = line.trim().split(/\s+/);
  if (parts.length < 3) return;
  const [oldRev, newRev, refName] = parts;

  // Check if reference is a tag
  if (refName.startsWith('refs/tags/')) {
    const tagName = refName.replace('refs/tags/', '');
    // Strip leading 'v' prefix if present
    const cleanTagName = tagName.startsWith('v') ? tagName.slice(1) : tagName;

    if (cleanTagName !== packageVersion) {
      console.error(`\n[Git Hook Error] Cannot create tag '${tagName}'.`);
      console.error(`The tag version does not match the package.json version (${packageVersion}).`);
      console.error(`Please update the version in package.json first or run: npm version <major|minor|patch>\n`);
      process.exit(1);
    }
  }
});

rl.on('close', () => {
  process.exit(0);
});
