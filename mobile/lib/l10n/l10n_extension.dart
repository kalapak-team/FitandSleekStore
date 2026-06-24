import 'package:flutter/widgets.dart';
import 'package:provider/provider.dart';

import '../providers/app_settings_provider.dart';
import 'admin_l10n.dart';
import 'storefront_l10n.dart';

extension StorefrontL10nContext on BuildContext {
  StorefrontL10n get l10n => StorefrontL10n(watch<AppSettingsProvider>().language);
  AdminL10n get adminL10n => AdminL10n(watch<AppSettingsProvider>().language);
}
