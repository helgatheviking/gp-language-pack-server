const fs = require('fs');
const path = require('path');

// Paths
const packageJsonPath = path.join(__dirname, '../package.json');
const phpFilePath = path.join(__dirname, '../gp-language-pack-server.php');

// Read package.json
if (!fs.existsSync(packageJsonPath)) {
  console.error('Error: package.json not found.');
  process.exit(1);
}

const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
const version = packageJson.version;

if (!version) {
  console.error('Error: No version field found in package.json.');
  process.exit(1);
}

// Read gp-language-pack-server.php
if (!fs.existsSync(phpFilePath)) {
  console.error('Error: gp-language-pack-server.php not found.');
  process.exit(1);
}

let phpContent = fs.readFileSync(phpFilePath, 'utf8');

// Replace Plugin Header Version:
// Match pattern like: * Version:      1.0.1
const versionHeaderRegex = /(\*\s*Version:\s*)(\S+)/;
if (versionHeaderRegex.test(phpContent)) {
  phpContent = phpContent.replace(versionHeaderRegex, `$1${version}`);
  console.log(`Updated plugin header version to: ${version}`);
} else {
  console.warn('Warning: Plugin Version header not found or matched.');
}

// Replace gp_language_pack_VERSION constant:
// Match pattern like: define( 'gp_language_pack_VERSION', '1.0.0' );
const versionConstantRegex = /(define\(\s*['"]gp_language_pack_VERSION['"]\s*,\s*['"])([^'"]+)(['"]\s*\);)/;
if (versionConstantRegex.test(phpContent)) {
  phpContent = phpContent.replace(versionConstantRegex, `$1${version}$3`);
  console.log(`Updated gp_language_pack_VERSION constant to: ${version}`);
} else {
  console.warn('Warning: gp_language_pack_VERSION constant not found or matched.');
}

// Write the updated file back
fs.writeFileSync(phpFilePath, phpContent, 'utf8');
console.log('Version update complete.');
