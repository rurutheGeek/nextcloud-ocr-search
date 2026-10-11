"""Sample pictures for the store screenshots: invented content only."""
import sys

from PIL import Image, ImageDraw, ImageFont
F = '/usr/share/fonts/truetype/dejavu/'
def font(size, bold=False): return ImageFont.truetype(F + ('DejaVuSans-Bold.ttf' if bold else 'DejaVuSans.ttf'), size)
def chat():
    im = Image.new('RGB', (1080, 1500), '#eef1f5'); d = ImageDraw.Draw(im)
    d.rectangle((0, 0, 1080, 130), fill='#1f6feb'); d.text((48, 40), 'Team Lunch', font=font(48, True), fill='white')
    rows = [(0, 'Are we still on for Friday?'), (1, 'Yes! Table booked at Trattoria Verde'), (1, '19:30, under the name Morgan'),
            (0, 'Perfect. Booking code is TV-48213'), (1, 'Bring the tickets for the concert')]
    y = 190
    for mine, text in rows:
        w = d.textlength(text, font=font(40)) + 70
        x = 1080 - w - 40 if mine else 40
        d.rounded_rectangle((x, y, x + w, y + 110), 34, fill='#1f6feb' if mine else 'white')
        d.text((x + 35, y + 30), text, font=font(40), fill='white' if mine else '#1c2733'); y += 160
    im.save(sys.argv[1] + '/chat-lunch.png')
def error():
    im = Image.new('RGB', (1400, 800), '#2b3440'); d = ImageDraw.Draw(im)
    d.rounded_rectangle((250, 170, 1150, 630), 18, fill='white'); d.rectangle((250, 170, 1150, 240), fill='#d0d7de')
    d.text((280, 186), 'Backup Tool', font=font(34, True), fill='#1c2733')
    d.text((300, 290), 'The backup could not be completed.', font=font(38), fill='#1c2733')
    d.text((300, 360), 'Error code 0x80070057', font=font(38, True), fill='#b42318')
    d.text((300, 430), 'Destination drive is write protected.', font=font(34), fill='#57606a')
    d.rounded_rectangle((900, 530, 1110, 600), 10, fill='#1f6feb'); d.text((955, 545), 'Retry', font=font(34, True), fill='white')
    im.save(sys.argv[1] + '/backup-error.png')
def wifi():
    im = Image.new('RGB', (1080, 1300), 'white'); d = ImageDraw.Draw(im)
    d.text((60, 70), 'Wi-Fi', font=font(64, True), fill='#1c2733')
    for i, (k, v) in enumerate([('Network', 'Harbor Cafe Guest'), ('Password', 'espresso-2026'), ('Security', 'WPA3 Personal'), ('IP address', '192.0.2.44'), ('Router', '192.0.2.1')]):
        y = 220 + i * 190
        d.text((60, y), k, font=font(34), fill='#57606a'); d.text((60, y + 52), v, font=font(46), fill='#1c2733'); d.line((60, y + 140, 1020, y + 140), fill='#d0d7de', width=3)
    im.save(sys.argv[1] + '/wifi-settings.png')
def order():
    im = Image.new('RGB', (1200, 1000), '#f6f8fa'); d = ImageDraw.Draw(im)
    d.rounded_rectangle((80, 80, 1120, 920), 24, fill='white', outline='#d0d7de', width=3)
    d.text((140, 140), 'Order confirmed', font=font(60, True), fill='#1a7f37')
    d.text((140, 250), 'Order number 7731-KQ', font=font(42), fill='#1c2733')
    for i, (k, v) in enumerate([('Mechanical keyboard', '89.00'), ('USB-C cable 2 m', '12.50'), ('Shipping', '4.90')]):
        y = 370 + i * 90; d.text((140, y), k, font=font(38), fill='#1c2733'); d.text((900, y), v, font=font(38), fill='#1c2733')
    d.line((140, 660, 1060, 660), fill='#d0d7de', width=3)
    d.text((140, 700), 'Total', font=font(44, True), fill='#1c2733'); d.text((880, 700), '106.40', font=font(44, True), fill='#1c2733')
    d.text((140, 810), 'Arrives Thursday, 15 October', font=font(36), fill='#57606a')
    im.save(sys.argv[1] + '/order-confirmation.png')
chat(); error(); wifi(); order()
