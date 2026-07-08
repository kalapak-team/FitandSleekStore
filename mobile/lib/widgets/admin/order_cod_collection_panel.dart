import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/api_client.dart';
import '../../services/admin_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/cod_settlement.dart';
import 'admin_ui_kit.dart';

class OrderCodCollectionPanel extends StatefulWidget {
  const OrderCodCollectionPanel({
    super.key,
    required this.order,
    required this.canEdit,
    required this.onCollected,
  });

  final AdminOrderDetail order;
  final bool canEdit;
  final ValueChanged<AdminOrderDetail> onCollected;

  @override
  State<OrderCodCollectionPanel> createState() => _OrderCodCollectionPanelState();
}

class _OrderCodCollectionPanelState extends State<OrderCodCollectionPanel> {
  CodQuote? _quote;
  bool _loading = false;
  CodKhqrPayment? _khqr;
  Timer? _pollTimer;
  String? _channel; // outdoor | khqr
  String? _cashSettlement; // cod_cash_usd | cod_cash_khr
  final _amountCtrl = TextEditingController();
  final _notesCtrl = TextEditingController();
  String _courierName = 'J&T Express';

  AdminService get _svc => AdminService(context.read<ApiClient>());

  @override
  void initState() {
    super.initState();
    _courierName = _defaultCourier(widget.order.shipmentProvider);
    if (widget.order.isCodPending) {
      _loadQuote();
    }
  }

  @override
  void didUpdateWidget(covariant OrderCodCollectionPanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.order.id != widget.order.id ||
        oldWidget.order.paymentStatus != widget.order.paymentStatus) {
      _pollTimer?.cancel();
      if (widget.order.isCodPending) {
        _loadQuote();
      }
    }
    if (oldWidget.order.shipmentProvider != widget.order.shipmentProvider) {
      _courierName = _defaultCourier(widget.order.shipmentProvider);
    }
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    _amountCtrl.dispose();
    _notesCtrl.dispose();
    super.dispose();
  }

  String _defaultCourier(String? provider) {
    if (provider == null || provider.isEmpty || provider == 'Internal') {
      return 'J&T Express';
    }
    return kCourierProviders.contains(provider) ? provider : provider;
  }

  Future<void> _loadQuote() async {
    try {
      final quote = await _svc.getCodQuote(widget.order.id);
      if (!mounted) return;
      setState(() => _quote = quote);
    } catch (_) {
      if (!mounted) return;
      setState(() => _quote = null);
    }
  }

  void _startPolling() {
    _pollTimer?.cancel();
    _pollTimer = Timer.periodic(const Duration(seconds: 3), (_) async {
      try {
        final result = await _svc.codKhqrStatus(widget.order.id);
        if (result.paymentStatus == 'paid' || result.order?.paymentStatus == 'paid') {
          _pollTimer?.cancel();
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('បានទទួលប្រាក់ KHQR / KHQR payment received.')),
          );
          if (result.order != null) {
            widget.onCollected(result.order!);
          } else {
            final refreshed = await _svc.getOrder(widget.order.id);
            if (mounted) widget.onCollected(refreshed);
          }
        }
      } catch (_) {}
    });
  }

  Future<void> _collectCash() async {
    if (!widget.canEdit || _cashSettlement == null) return;
    final parsed = double.tryParse(_amountCtrl.text.trim());
    if (parsed == null || parsed < 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('បញ្ចូលចំនួនទទួលពិត / Enter amount received')),
      );
      return;
    }
    setState(() => _loading = true);
    try {
      final order = await _svc.collectCodCash(
        orderId: widget.order.id,
        settlementMethod: _cashSettlement!,
        actualAmountReceived: parsed,
        courierName: _courierName,
        notes: _notesCtrl.text.trim().isEmpty ? null : _notesCtrl.text.trim(),
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('បានកត់ត្រាបង់រួច / Payment recorded')),
      );
      widget.onCollected(order);
    } on DioException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.read<ApiClient>().apiMessage(e))),
      );
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _generateKhqr() async {
    if (!widget.canEdit) return;
    setState(() => _loading = true);
    try {
      final payment = await _svc.createCodKhqr(
        orderId: widget.order.id,
        courierName: _courierName,
        notes: _notesCtrl.text.trim().isEmpty ? null : _notesCtrl.text.trim(),
      );
      if (!mounted) return;
      setState(() => _khqr = payment);
      _startPolling();
    } on DioException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.read<ApiClient>().apiMessage(e))),
      );
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _selectCashSettlement(String method) {
    setState(() {
      _cashSettlement = method;
      if (_quote != null) {
        _amountCtrl.text = method == 'cod_cash_khr'
            ? '${_quote!.khrAmount}'
            : _quote!.usdAmount.toStringAsFixed(2);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final order = widget.order;
    if (!order.isCod) return const SizedBox.shrink();

    final paid = settlementFromPayments(order.payments);
    if (!order.isCodPending && paid != null) {
      return AdminSoftCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'COD — បានបង់រួច / Paid on delivery',
              style: TextStyle(
                fontWeight: FontWeight.w800,
                color: AppColors.success,
              ),
            ),
            const SizedBox(height: 8),
            Text('របៀបប្រមូល: ${paid.channelKm}'),
            Text('លម្អិត: ${paid.labelKm}'),
            if (paid.actualLabel != null) Text('ចំនួនទទួលពិត: ${paid.actualLabel}'),
            if (paid.courierName != null) Text('អ្នកដឹក: ${paid.courierName}'),
            if (paid.notes != null && paid.notes!.isNotEmpty) Text('កំណត់ហតុ: ${paid.notes}'),
          ],
        ),
      );
    }

    if (!order.isCodPending) return const SizedBox.shrink();

    final usdLabel = _quote != null ? '\$${_quote!.usdAmount.toStringAsFixed(2)}' : '—';
    final khrLabel = _quote != null
        ? '${_quote!.khrAmount.toString().replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?!\d))'), (m) => '${m[1]},')} ៛'
        : '—';

    return AdminSoftCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'COD — ប្រមូលលុយ / Collect payment',
            style: TextStyle(fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 4),
          Text(
            'ត្រូវបង់: $usdLabel ≈ $khrLabel'
            '${_quote != null ? ' (${_quote!.exchangeRate} ៛/USD)' : ''}',
            style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant, fontSize: 13),
          ),
          if (!widget.canEdit) ...[
            const SizedBox(height: 8),
            const Text('No permission to record COD payments.', style: TextStyle(fontSize: 12)),
          ] else ...[
            const SizedBox(height: 12),
            const Text('ជំហាន ១ — របៀបប្រមូល', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: _ChannelChip(
                    label: 'លុយក្រៅ',
                    selected: _channel == 'outdoor',
                    onTap: _loading
                        ? null
                        : () => setState(() {
                              _channel = 'outdoor';
                              _khqr = null;
                              _pollTimer?.cancel();
                              _cashSettlement = null;
                            }),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _ChannelChip(
                    label: 'Scan KHQR',
                    selected: _channel == 'khqr',
                    onTap: _loading ? null : () => setState(() {
                          _channel = 'khqr';
                          _cashSettlement = null;
                        }),
                  ),
                ),
              ],
            ),
            if (_channel == 'outdoor') ...[
              const SizedBox(height: 12),
              const Text('ជំហាន ២ — រូបិយប័ណ្ណ', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: _ChannelChip(
                      label: 'USD\n$usdLabel',
                      selected: _cashSettlement == 'cod_cash_usd',
                      onTap: _loading ? null : () => _selectCashSettlement('cod_cash_usd'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: _ChannelChip(
                      label: 'KHR\n$khrLabel',
                      selected: _cashSettlement == 'cod_cash_khr',
                      onTap: _loading ? null : () => _selectCashSettlement('cod_cash_khr'),
                    ),
                  ),
                ],
              ),
              if (_cashSettlement != null) ...[
                const SizedBox(height: 12),
                TextField(
                  controller: _amountCtrl,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(
                    labelText: 'ចំនួនទទួលពិត / Amount received',
                    isDense: true,
                  ),
                ),
                const SizedBox(height: 8),
                DropdownButtonFormField<String>(
                  value: _courierName,
                  decoration: const InputDecoration(labelText: 'អ្នកដឹក / Courier', isDense: true),
                  items: [
                    ...kCourierProviders.map((c) => DropdownMenuItem(value: c, child: Text(c))),
                    if (!kCourierProviders.contains(_courierName))
                      DropdownMenuItem(value: _courierName, child: Text(_courierName)),
                  ],
                  onChanged: _loading
                      ? null
                      : (v) {
                          if (v != null) setState(() => _courierName = v);
                        },
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: _notesCtrl,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'កំណត់ហតុ / Notes',
                    isDense: true,
                  ),
                ),
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: _loading ? null : _collectCash,
                    style: FilledButton.styleFrom(backgroundColor: AppColors.storeHeader),
                    child: Text(_loading ? '…' : 'កត់ត្រាបង់រួច / Record payment'),
                  ),
                ),
              ],
            ],
            if (_channel == 'khqr') ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                value: _courierName,
                decoration: const InputDecoration(labelText: 'អ្នកដឹក / Courier', isDense: true),
                items: [
                  ...kCourierProviders.map((c) => DropdownMenuItem(value: c, child: Text(c))),
                  if (!kCourierProviders.contains(_courierName))
                    DropdownMenuItem(value: _courierName, child: Text(_courierName)),
                ],
                onChanged: _loading
                    ? null
                    : (v) {
                        if (v != null) setState(() => _courierName = v);
                      },
              ),
              const SizedBox(height: 8),
              TextField(
                controller: _notesCtrl,
                maxLines: 2,
                decoration: const InputDecoration(labelText: 'កំណត់ហតុ / Notes', isDense: true),
              ),
              if (_khqr?.qrString == null || _khqr!.qrString!.isEmpty) ...[
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: _loading ? null : _generateKhqr,
                    style: FilledButton.styleFrom(backgroundColor: const Color(0xFFC0272D)),
                    child: Text(_loading ? '…' : 'បង្កើត KHQR ($khrLabel)'),
                  ),
                ),
              ],
            ],
          ],
          if (_khqr?.qrString != null && _khqr!.qrString!.isNotEmpty) ...[
            const SizedBox(height: 16),
            Center(
              child: Column(
                children: [
                  const Text('Show QR for customer to scan', style: TextStyle(fontWeight: FontWeight.w700, color: Color(0xFFC0272D))),
                  const SizedBox(height: 8),
                  QrImageView(
                    data: _khqr!.qrString!,
                    size: 200,
                    backgroundColor: Colors.white,
                  ),
                  if (_khqr!.amount != null)
                    Text(
                      '${_khqr!.amount!.toStringAsFixed(0)} ${_khqr!.currency ?? 'KHR'}',
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                  const Text('កំពុងរង់ចាំការបង់… / Waiting…', style: TextStyle(fontSize: 12)),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ChannelChip extends StatelessWidget {
  const _ChannelChip({required this.label, required this.selected, this.onTap});

  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? AppColors.storeHeader.withValues(alpha: 0.15) : Theme.of(context).colorScheme.surface,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: selected ? AppColors.storeHeader : Theme.of(context).dividerColor,
              width: selected ? 2 : 1,
            ),
          ),
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              fontSize: 13,
              height: 1.25,
              color: Theme.of(context).colorScheme.onSurface,
            ),
          ),
        ),
      ),
    );
  }
}
