project('porta-desktop', 'c', version: '0.0.0', default_options: ['c_std=gnu11', 'warning_level=2'], meson_version: '>=1.1')

glib = dependency('glib-2.0', version: '>=2.74')
core = static_library('porta-desktop-core', 'src/core.c', dependencies: glib)

if get_option('app')
  executable('porta-desktop', 'src/main.c',
    link_with: core,
    dependencies: [
      glib,
      dependency('gio-unix-2.0'),
      dependency('gtk4', version: '>=4.10'),
      dependency('vte-2.91-gtk4', version: '>=0.70'),
      dependency('json-glib-1.0'),
    ],
    install: true,
    install_dir: get_option('libdir') / 'porta-desktop',
  )
endif

test('core', executable('test-core', 'tests/test_core.c',
  link_with: core,
  dependencies: glib,
  c_args: ['-DCONTRACT_DIR="' + meson.project_source_root() / '..' / 'contract' + '"'],
))
