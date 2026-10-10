/**
 * The header's icons that can be switched off (App\Support\HeaderIcons on the
 * server): for the whole company (System Settings -> Defaults -> Users) and by
 * each user (Profile). Order = the standard left-to-right order in the header;
 * each user can change it in Profile (users.header_icons "_order",
 * auth.headerOrder). A new icon gets a key here AND in HeaderIcons::KEYS.
 */
export const HEADER_ICONS = [
  { key: 'pos', label: 'POS button', help: 'Also needs the role\'s POS permission.' },
  { key: 'customize', label: 'Settings (theme and layout)' },
  { key: 'install', label: 'Install app', help: 'Only shows while the browser offers to install it.' },
  { key: 'summary', label: 'Como Vamos! (today\'s summary)' },
  { key: 'fullscreen', label: 'Full screen' },
  { key: 'manual', label: 'User manual', help: 'This page or the whole manual, only the modules and parts the user\'s role allows.' },
  { key: 'language', label: 'Language (translate)' },
  { key: 'notifications', label: 'Notifications' },
  { key: 'clock', label: 'Clock in / out', help: 'Also needs the role\'s Clock in / out permission.' },
  { key: 'logout', label: 'Log out', help: 'Logging out stays in the user menu.' },
];
