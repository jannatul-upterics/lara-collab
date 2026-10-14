import TableRowActions from '@/components/TableRowActions';
import { Link } from '@inertiajs/react';
import { Badge, Group, Table, Text } from '@mantine/core';

export default function TableRow({ item }) {
  return (
    <Table.Tr key={item.id}>
      <Table.Td>
        <Text
          fz='sm'
          fw={500}
        >
          {item.name}
        </Text>
      </Table.Td>
      <Table.Td>
        <Text fz='sm'>{item.email}</Text>
        <Text
          fz='xs'
          c='dimmed'
        >
          Email
        </Text>
      </Table.Td>
      <Table.Td>
        <Group gap='sm'>
          {item.clients.map(item => (
            <Link
              href={route('clients.users.edit', item.id)}
              key={item.id}
            >
              <Badge
                variant='light'
                color='orange'
                tt='unset'
              >
                {item.name}
              </Badge>
            </Link>
          ))}
        </Group>
      </Table.Td>
      {(can('edit client company') ||
        can('archive client company') ||
        can('restore client company') ||
        (typeof isAdmin === 'function' && isAdmin() && route().params.archived)) && (
        <Table.Td>
          <TableRowActions
            item={item}
            editRoute='clients.companies.edit'
            editPermission='edit client company'
            archivePermission='archive client company'
            restorePermission='restore client company'
            archive={{
              route: 'clients.companies.destroy',
              title: 'Archive company',
              content: `Are you sure you want to archive this company?`,
              confirmLabel: 'Archive',
            }}
            restore={{
              route: 'clients.companies.restore',
              title: 'Restore company',
              content: `Are you sure you want to restore this company?`,
              confirmLabel: 'Restore',
            }}
            permanentDelete={{
              route: 'clients.companies.permanent-delete',
              title: 'Permanently delete company',
              content: `Are you sure you want to permanently delete company "${item.name}"? This operation is irreversible and the record cannot be restored afterward.`,
              confirmLabel: 'Delete permanently',
            }}
          />
        </Table.Td>
      )}
    </Table.Tr>
  );
}
