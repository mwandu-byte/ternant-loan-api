// One-off: renders every SLoan / CareSmart Loans raster asset from the
// master SVGs in branding/. Uses the web app's sharp:
//   NODE_PATH=loan-frontend/node_modules node branding/generate-assets.cjs .
// then run `dart run flutter_launcher_icons` in ternant-loan-app/.
// sharp can't write .ico, so loan-frontend/app/favicon.ico is assembled
// afterwards from the branding/.favicon-{16,32,48}.png it leaves behind:
//   python3 -c "from PIL import Image; i=[Image.open(f'branding/.favicon-{s}.png') for s in (16,32,48)];
//     i[2].save('loan-frontend/app/favicon.ico', sizes=[(16,16),(32,32),(48,48)], append_images=i[:2])"
//   rm branding/.favicon-*.png
const sharp = require('sharp');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(process.argv[2]);
const B = path.join(ROOT, 'branding');
const APP = path.join(ROOT, 'ternant-loan-app');
const WEB = path.join(ROOT, 'loan-frontend');

const mark = fs.readFileSync(path.join(B, 'sloan-mark.svg'), 'utf8');
const defs = mark.match(/<defs>[\s\S]*?<\/defs>/)[0];
const glyph = mark.match(/<g transform="translate\(-4 14\)">[\s\S]*?<\/g>/)[0];
const svg = (body) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">${defs}${body}</svg>`;
const scaled = (s) => `<g transform="translate(256 256) scale(${s}) translate(-256 -256)">${glyph}</g>`;

const variants = {
  rounded: mark,                                                            // tile with rounded corners
  fullBleed: svg(`<rect width="512" height="512" fill="url(#sloan-tile)"/>${glyph}`), // OS applies its own mask
  maskable: svg(`<rect width="512" height="512" fill="url(#sloan-tile)"/>${scaled(0.78)}`),
  foreground: svg(scaled(1.0)),                                             // adaptive-icon safe zone
  background: svg(`<rect width="512" height="512" fill="url(#sloan-tile)"/>`),
};

const out = async (variant, size, file) => {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await sharp(Buffer.from(variants[variant])).resize(size, size).png().toFile(file);
};

(async () => {
  // Flutter app
  await out('fullBleed', 1024, `${APP}/assets/icon/icon.png`);
  await out('foreground', 1024, `${APP}/assets/icon/icon_foreground.png`);
  await out('background', 1024, `${APP}/assets/icon/icon_background.png`);
  await out('maskable', 1024, `${APP}/assets/icon/icon_maskable.png`);
  await out('rounded', 512, `${APP}/assets/branding/mark.png`);
  await sharp(path.join(B, 'sloan-logo-horizontal-dark.svg')).resize(1280).png().toFile(`${APP}/assets/branding/logo.png`);
  const densities = { mdpi: 96, hdpi: 144, xhdpi: 192, xxhdpi: 288, xxxhdpi: 384 };
  for (const [d, px] of Object.entries(densities)) {
    await out('rounded', px, `${APP}/android/app/src/main/res/drawable-${d}/launch_image.png`);
  }
  const launch = `${APP}/ios/Runner/Assets.xcassets/LaunchImage.imageset`;
  await out('rounded', 120, `${launch}/LaunchImage.png`);
  await out('rounded', 240, `${launch}/LaunchImage@2x.png`);
  await out('rounded', 360, `${launch}/LaunchImage@3x.png`);

  // Web app (Next.js metadata file conventions + manifest icons)
  fs.copyFileSync(path.join(B, 'sloan-mark.svg'), `${WEB}/app/icon.svg`);
  await out('fullBleed', 180, `${WEB}/app/apple-icon.png`);
  for (const px of [16, 32, 48]) await out('rounded', px, `${ROOT}/branding/.favicon-${px}.png`);
  await out('rounded', 192, `${WEB}/public/icons/icon-192.png`);
  await out('rounded', 512, `${WEB}/public/icons/icon-512.png`);
  await out('maskable', 512, `${WEB}/public/icons/icon-maskable-512.png`);

  // Previews of the masters themselves, kept alongside them
  await out('rounded', 1024, `${B}/sloan-mark.png`);
  await sharp(path.join(B, 'sloan-logo-horizontal-light.svg')).resize(1880).png().toFile(`${B}/sloan-logo-horizontal-light.png`);
  await sharp(path.join(B, 'sloan-logo-horizontal-dark.svg')).resize(1880).png().toFile(`${B}/sloan-logo-horizontal-dark.png`);
  console.log('done');
})();
