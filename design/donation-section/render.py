"""Render and verify the donation QR from the plugin's authoritative address; optionally sync its runtime PNG."""
import argparse
from copy import deepcopy
from pathlib import Path
import hashlib
import json
import re
import shutil
import xml.etree.ElementTree as ET

import cairosvg
from PIL import Image
import segno
import zxingcpp

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--sync-runtime', action='store_true', help='Replace the bundled donation PNG only after successful decoding checks')
args = parser.parse_args()
source = ROOT / 'src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php'
match = re.search(r"protected \$support_btc_address = '([a-zA-Z0-9]+)';", source.read_text())
if not match:
    raise ValueError('Cannot locate the current donation address; do not substitute an example.')
address = match.group(1)
# This donation target is a mainnet SegWit-v0 address. Reject typos rather than producing a scannable but invalid destination.
charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l'
hrp, separator, encoded = address.rpartition('1')
if hrp != 'bc' or not separator or any(char not in charset for char in encoded):
    raise ValueError('Expected a Bitcoin mainnet Bech32 donation address.')
values = [charset.index(char) for char in encoded]
checksum = 1
for value in [ord(char) >> 5 for char in hrp] + [0] + [ord(char) & 31 for char in hrp] + values:
    top = checksum >> 25
    checksum = ((checksum & 0x1ffffff) << 5) ^ value
    for index, generator in enumerate([0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3]):
        if (top >> index) & 1:
            checksum ^= generator
if checksum != 1 or len(values) != 39 or values[0] != 0:
    raise ValueError('Invalid mainnet SegWit-v0 20-byte donation address checksum or length.')
payload = 'bitcoin:' + address
qr = segno.make_qr(payload, error='h', boost_error=False)
quiet = 4
modules = len(qr.matrix)
side = modules + quiet * 2
ns = 'http://www.w3.org/2000/svg'
ET.register_namespace('', ns)
svg = ET.Element('{' + ns + '}svg', {'width': str(side * 12), 'height': str(side * 12), 'viewBox': f'0 0 {side} {side}', 'role': 'img', 'aria-label': 'Bitcoin donation QR — PayCrypto.Me'})
ET.SubElement(svg, '{' + ns + '}title').text = 'PayCrypto.Me — Bitcoin donation QR'
ET.SubElement(svg, '{' + ns + '}rect', {'width': str(side), 'height': str(side), 'fill': '#FFFFFF'})
path = ''.join(f'M{x + quiet} {y + quiet}h1v1h-1z' for y, row in enumerate(qr.matrix) for x, dark in enumerate(row) if dark)
ET.SubElement(svg, '{' + ns + '}path', {'d': path, 'fill': '#101318'})
# Keep complete finder patterns and four-module quiet zone; the logo's white plate is nine modules wide.
plate = (side - 9) // 2
ET.SubElement(svg, '{' + ns + '}rect', {'x': str(plate), 'y': str(plate), 'width': '9', 'height': '9', 'fill': '#FFFFFF'})
logo = deepcopy(ET.parse(HERE / 'brand.svg').getroot())
logo.set('x', str(plate + 1)); logo.set('y', str(plate + 1))
logo.set('width', '7'); logo.set('height', '7')
svg.append(logo)
ET.ElementTree(svg).write(HERE / 'donation-qr.svg', encoding='utf-8', xml_declaration=True)
cairosvg.svg2png(url=str(HERE / 'donation-qr.svg'), write_to=str(HERE / 'donation-qr.png'))
with Image.open(HERE / 'donation-qr.png') as image:
    image.convert('RGB').save(HERE / 'donation-qr.png', optimize=True, compress_level=9)

checks = []
with Image.open(HERE / 'donation-qr.png') as image:
    for size in [95, 110, 120, 128, 144, 160, 240, 256, image.width]:
        rendered = image.convert('RGB').resize((size, size), Image.Resampling.LANCZOS)
        decoded = zxingcpp.read_barcode(rendered)
        if decoded is None or decoded.text != payload:
            raise ValueError(f'QR decode mismatch at {size}px')
        checks.append(size)
if args.sync_runtime:
    shutil.copyfile(HERE / 'donation-qr.png', ROOT / 'src/trunk/assets/wallet_address_qrcode.png')
manifest = {'status': 'Owner-supplied address and branded QR prepared locally; compact donation UI remains a design study. No release published.', 'address': address, 'payload': payload, 'error_correction': 'H', 'quiet_zone_modules': quiet, 'matrix_modules': modules, 'center_plate_modules': 9, 'center_logo_modules': 7, 'png_dimensions': [side * 12, side * 12], 'decoded_at_pixels': checks, 'brand_sha256': hashlib.sha256((HERE / 'brand.svg').read_bytes()).hexdigest()}
(HERE / 'preview-data.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(json.dumps(manifest, indent=2))
