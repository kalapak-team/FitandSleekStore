import 'package:flutter/material.dart';

import '../../services/admin_service.dart';
import 'admin_ui_kit.dart';

class OrderDeliveryPanel extends StatefulWidget {
  const OrderDeliveryPanel({
    super.key,
    required this.order,
    required this.canEdit,
    required this.onSaved,
    required this.onOpenShopInvoice,
    required this.onSaveDeliveredBy,
  });

  final AdminOrderDetail order;
  final bool canEdit;
  final VoidCallback onSaved;
  final VoidCallback onOpenShopInvoice;
  final Future<void> Function(String? name) onSaveDeliveredBy;

  @override
  State<OrderDeliveryPanel> createState() => _OrderDeliveryPanelState();
}

class _OrderDeliveryPanelState extends State<OrderDeliveryPanel> {
  late final TextEditingController _driverNameController;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _driverNameController = TextEditingController(text: widget.order.deliveredByName ?? '');
  }

  @override
  void didUpdateWidget(covariant OrderDeliveryPanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.order.id != widget.order.id ||
        oldWidget.order.deliveredByName != widget.order.deliveredByName) {
      _driverNameController.text = widget.order.deliveredByName ?? '';
    }
  }

  @override
  void dispose() {
    _driverNameController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      await widget.onSaveDeliveredBy(_driverNameController.text);
      if (!mounted) return;
      widget.onSaved();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('ឈ្មោះអ្នកដឹកបានរក្សាទុក / Driver name saved')),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Could not save driver name')),
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final order = widget.order;
    final courier = order.courierLabel;

    return AdminSoftCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const AdminSectionTitle(title: 'Delivery / ការដឹកជញ្ជូន'),
          const SizedBox(height: 8),
          if (courier != null)
            Text('ក្រុមហ៊ុនដឹក / Courier: $courier', style: const TextStyle(fontWeight: FontWeight.w600)),
          if (order.shipmentStatus != null)
            Text('ស្ថានភាព / Status: ${order.shipmentStatus}'),
          if (order.deliveredByName != null && order.deliveredByName!.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'ដឹកដោយ / Delivered by: ${order.deliveredByName}',
                style: TextStyle(
                  fontWeight: FontWeight.w700,
                  color: Theme.of(context).colorScheme.primary,
                ),
              ),
            ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: widget.onOpenShopInvoice,
            icon: const Icon(Icons.receipt_long_outlined, size: 18),
            label: const Text('វិក័យបត្រ ហាង / Shop delivery note'),
          ),
          if (widget.canEdit && order.shipmentId != null) ...[
            const SizedBox(height: 12),
            TextField(
              controller: _driverNameController,
              decoration: const InputDecoration(
                labelText: 'ឈ្មោះអ្នកដឹក / Driver name',
                hintText: 'Name when package is delivered',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save driver name'),
              ),
            ),
          ] else if (widget.canEdit && order.shipmentId == null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                'Add courier tracking on web admin to enable driver name.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
        ],
      ),
    );
  }
}
