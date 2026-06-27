import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../l10n/l10n_extension.dart';
import '../models/address_model.dart';
import '../models/cart_model.dart';
import '../models/khqr_bank_option.dart';
import '../models/order_model.dart';
import '../providers/auth_provider.dart';
import '../screens/admin/admin_shell.dart';
import '../services/order_service.dart';
import '../services/profile_service.dart';
import '../theme/app_colors.dart';
import '../widgets/common/fs_button.dart';
import 'order_detail_screen.dart';
import 'payment_screen.dart';

class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key, required this.cart});

  final CartModel cart;

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _provinceController = TextEditingController(text: 'Phnom Penh');
  final _addressController = TextEditingController();

  List<AddressModel> _addresses = [];
  AddressModel? _selectedAddress;
  bool _loadingAddresses = true;
  bool _useManualEntry = false;
  bool _loading = false;
  String? _addressError;
  KhqrBankId _selectedBank = KhqrBankId.aba;

  ProfileService get _profileService => ProfileService(context.read<ApiClient>());

  @override
  void initState() {
    super.initState();
    _loadAddresses();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _provinceController.dispose();
    _addressController.dispose();
    super.dispose();
  }

  Future<void> _loadAddresses() async {
    setState(() {
      _loadingAddresses = true;
      _addressError = null;
    });
    try {
      final items = await _profileService.listAddresses();
      AddressModel? selected;
      if (items.isNotEmpty) {
        selected = items.firstWhere(
          (a) => a.isDefault,
          orElse: () => items.first,
        );
      }
      if (!mounted) return;
      setState(() {
        _addresses = items;
        _selectedAddress = selected;
        _useManualEntry = items.isEmpty;
        _loadingAddresses = false;
      });
      if (selected != null) {
        _applyAddressToForm(selected);
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _addressError = e.toString();
        _loadingAddresses = false;
        _useManualEntry = true;
      });
    }
  }

  void _applyAddressToForm(AddressModel address) {
    _nameController.text = address.receiverName ?? '';
    _phoneController.text = address.receiverPhone ?? '';
    _provinceController.text = address.province ?? '';
    _addressController.text = address.displayLines;
  }

  void _selectAddress(AddressModel address) {
    setState(() {
      _selectedAddress = address;
      _useManualEntry = false;
    });
    _applyAddressToForm(address);
  }

  Map<String, dynamic> _buildShippingAddress() {
    if (!_useManualEntry && _selectedAddress != null) {
      return _selectedAddress!.toCheckoutPayload();
    }
    final street = _addressController.text.trim();
    return {
      'receiver_name': _nameController.text.trim(),
      'receiver_phone': _phoneController.text.trim(),
      'province': _provinceController.text.trim(),
      'house_no': street,
      'street_no': street,
      'sangkat': _provinceController.text.trim(),
      'khan': _provinceController.text.trim(),
      if (street.isNotEmpty) 'formatted_address': street,
    };
  }

  Future<void> _submit() async {
    if (!_useManualEntry && _selectedAddress == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.l10nRead.selectShippingAddress)),
      );
      return;
    }
    if (_useManualEntry && !_formKey.currentState!.validate()) return;

    setState(() => _loading = true);
    try {
      final order = await context.read<OrderService>().checkout(
            paymentMethod: 'bakong_khqr',
            shippingAddress: _buildShippingAddress(),
          );
      if (!mounted) return;
      final paid = await Navigator.of(context).push<bool>(
        MaterialPageRoute(
          builder: (_) => PaymentScreen(
            orderId: order.id,
            orderNumber: order.orderNumber,
            amount: order.total,
            bank: _selectedBank,
          ),
        ),
      );
      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => OrderDetailScreen(
            order: paid == true
                ? OrderModel(
                    id: order.id,
                    orderNumber: order.orderNumber,
                    status: 'processing',
                    paymentStatus: 'paid',
                    total: order.total,
                    paymentMethod: order.paymentMethod,
                    items: order.items,
                  )
                : order,
          ),
        ),
      );
    } on DioException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.read<ApiClient>().apiMessage(e))),
      );
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final user = context.watch<AuthProvider>().user;
    if (user != null && !user.canPlaceStorefrontOrder) {
      return Scaffold(
        appBar: AppBar(title: Text(l10n.proceedCheckout)),
        body: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Icon(Icons.storefront_outlined, size: 48, color: Theme.of(context).colorScheme.primary),
              const SizedBox(height: 16),
              Text(
                l10n.adminCannotCheckout,
                style: Theme.of(context).textTheme.bodyLarge,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              FsButton(
                label: l10n.adminOpenPosCheckout,
                variant: FsButtonVariant.accent,
                icon: Icons.point_of_sale_rounded,
                onPressed: () {
                  Navigator.of(context).pushAndRemoveUntil(
                    MaterialPageRoute(builder: (_) => const AdminShell()),
                    (_) => false,
                  );
                },
              ),
            ],
          ),
        ),
      );
    }

    final currency = NumberFormat.simpleCurrency(name: 'USD');
    final onSurface = Theme.of(context).colorScheme.onSurface;
    final onSurfaceVariant = Theme.of(context).colorScheme.onSurfaceVariant;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.proceedCheckout)),
      body: _loadingAddresses
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _loadAddresses,
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      '${l10n.orderTotalLabel}: ${currency.format(widget.cart.total)}',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    const SizedBox(height: 20),
                    if (_addressError != null) ...[
                      Text(_addressError!, style: TextStyle(color: onSurfaceVariant)),
                      const SizedBox(height: 12),
                    ],
                    if (_addresses.isNotEmpty && !_useManualEntry) ...[
                      Text(
                        l10n.savedAddresses,
                        style: Theme.of(context).textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.w700,
                              color: onSurface,
                            ),
                      ),
                      const SizedBox(height: 10),
                      ..._addresses.map(
                        (address) => _CheckoutAddressCard(
                          address: address,
                          selected: _selectedAddress?.id == address.id,
                          onTap: () => _selectAddress(address),
                        ),
                      ),
                      const SizedBox(height: 8),
                      Align(
                        alignment: Alignment.centerRight,
                        child: TextButton(
                          onPressed: () => setState(() => _useManualEntry = true),
                          child: Text(l10n.useDifferentAddress),
                        ),
                      ),
                    ] else ...[
                      if (_addresses.isEmpty)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Text(
                            l10n.addressesEmpty,
                            style: TextStyle(color: onSurfaceVariant),
                          ),
                        ),
                      if (_addresses.isNotEmpty)
                        Align(
                          alignment: Alignment.centerRight,
                          child: TextButton(
                            onPressed: () {
                              setState(() {
                                _useManualEntry = false;
                                if (_selectedAddress != null) {
                                  _applyAddressToForm(_selectedAddress!);
                                }
                              });
                            },
                            child: Text(l10n.useSavedAddress),
                          ),
                        ),
                      Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            TextFormField(
                              controller: _nameController,
                              decoration: InputDecoration(labelText: l10n.receiverName),
                              validator: (v) =>
                                  v == null || v.trim().isEmpty ? l10n.fieldRequired : null,
                            ),
                            const SizedBox(height: 12),
                            TextFormField(
                              controller: _phoneController,
                              keyboardType: TextInputType.phone,
                              decoration: InputDecoration(labelText: l10n.receiverPhone),
                              validator: (v) =>
                                  v == null || v.trim().isEmpty ? l10n.fieldRequired : null,
                            ),
                            const SizedBox(height: 12),
                            TextFormField(
                              controller: _provinceController,
                              decoration: InputDecoration(labelText: l10n.province),
                              validator: (v) =>
                                  v == null || v.trim().isEmpty ? l10n.fieldRequired : null,
                            ),
                            const SizedBox(height: 12),
                            TextFormField(
                              controller: _addressController,
                              maxLines: 2,
                              decoration: InputDecoration(labelText: l10n.deliveryAddress),
                              validator: (v) =>
                                  v == null || v.trim().isEmpty ? l10n.fieldRequired : null,
                            ),
                          ],
                        ),
                      ),
                    ],
                    const SizedBox(height: 20),
                    Text(
                      l10n.paymentMethodTitle,
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                            color: onSurface,
                          ),
                    ),
                    const SizedBox(height: 10),
                    ...KhqrBankOption.all.map(
                      (bank) => _CheckoutBankCard(
                        bank: bank,
                        selected: _selectedBank == bank.id,
                        onTap: () => setState(() => _selectedBank = bank.id),
                      ),
                    ),
                    const SizedBox(height: 24),
                    FsButton(
                      label: l10n.placeOrder,
                      variant: FsButtonVariant.accent,
                      loading: _loading,
                      onPressed: _loading ? null : _submit,
                    ),
                  ],
                ),
              ),
            ),
    );
  }
}

class _CheckoutAddressCard extends StatelessWidget {
  const _CheckoutAddressCard({
    required this.address,
    required this.selected,
    required this.onTap,
  });

  final AddressModel address;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final onSurface = Theme.of(context).colorScheme.onSurface;
    final onSurfaceVariant = Theme.of(context).colorScheme.onSurfaceVariant;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: selected ? AppColors.storeHeader : Theme.of(context).dividerColor,
                width: selected ? 2 : 1,
              ),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  selected ? Icons.radio_button_checked : Icons.radio_button_off,
                  color: selected ? AppColors.storeHeader : onSurfaceVariant,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              address.label,
                              style: TextStyle(
                                fontWeight: FontWeight.w700,
                                color: onSurface,
                              ),
                            ),
                          ),
                          if (address.isDefault)
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                              decoration: BoxDecoration(
                                color: onSurfaceVariant.withValues(alpha: 0.15),
                                borderRadius: BorderRadius.circular(999),
                              ),
                              child: Text(
                                l10n.defaultAddress,
                                style: TextStyle(fontSize: 11, color: onSurfaceVariant),
                              ),
                            ),
                        ],
                      ),
                      if ((address.receiverName ?? '').isNotEmpty) ...[
                        const SizedBox(height: 4),
                        Text(address.receiverName!, style: TextStyle(color: onSurface)),
                      ],
                      if ((address.receiverPhone ?? '').isNotEmpty)
                        Text(address.receiverPhone!, style: TextStyle(color: onSurfaceVariant)),
                      const SizedBox(height: 4),
                      Text(
                        address.displayLines,
                        style: TextStyle(color: onSurfaceVariant, height: 1.35),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _CheckoutBankCard extends StatelessWidget {
  const _CheckoutBankCard({
    required this.bank,
    required this.selected,
    required this.onTap,
  });

  final KhqrBankOption bank;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final isKm = l10n.isKm;
    final onSurface = Theme.of(context).colorScheme.onSurface;
    final onSurfaceVariant = Theme.of(context).colorScheme.onSurfaceVariant;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: selected ? AppColors.storeHeader : Theme.of(context).dividerColor,
                width: selected ? 2 : 1,
              ),
            ),
            child: Row(
              children: [
                Icon(
                  selected ? Icons.radio_button_checked : Icons.radio_button_off,
                  color: selected ? AppColors.storeHeader : onSurfaceVariant,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        isKm ? bank.labelKm : bank.labelEn,
                        style: TextStyle(fontWeight: FontWeight.w700, color: onSurface),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        isKm ? bank.subtitleKm : bank.subtitleEn,
                        style: TextStyle(color: onSurfaceVariant, fontSize: 13),
                      ),
                    ],
                  ),
                ),
                if (bank.id == KhqrBankId.aba)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: const Color(0xFF055E7A),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: const Text(
                      'ABA',
                      style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 11),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
