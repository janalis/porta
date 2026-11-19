# dmgbuild settings: the window Finder shows when the disk image opens.
# Positions match desktop/macos/dmg-background.py. `app` and `background` come from
# the command line (-D app=… -D background=…): dmgbuild runs this file without __file__.
import os.path

application = defines['app']  # noqa: F821 - dmgbuild provides `defines`

format = 'UDZO'
files = [application]
symlinks = {'Applications': '/Applications'}
badge_icon = None
icon_locations = {os.path.basename(application): (170, 150), 'Applications': (490, 150)}
background = defines['background']  # noqa: F821
window_rect = ((200, 120), (660, 400))
default_view = 'icon-view'
show_status_bar = False
show_tab_view = False
show_toolbar = False
show_pathbar = False
show_sidebar = False
icon_size = 128
text_size = 13
