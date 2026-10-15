import ErrorBoundary from "@/components/ErrorBoundary";
import FlashNotification from "@/components/FlashNotification";
import useNotificationsStore from "@/hooks/store/useNotificationsStore";
import useAuthorization from "@/hooks/useAuthorization";
import useWebSockets from "@/hooks/useWebSockets";
import NavBarNested from "@/layouts/NavBarNested";
import Notifications from "@/layouts/Notifications";
import { Head, usePage } from "@inertiajs/react";
import { AppShell } from "@mantine/core";
import { useEffect } from "react";

export default function MainLayout({ children, title }) {
  const { can, isAdmin } = useAuthorization();
  window.can = can;
  window.isAdmin = isAdmin;

  const { initUserWebSocket } = useWebSockets();
  const auth = usePage().props.auth;
  const notifications = auth?.notifications;
  const { setNotifications } = useNotificationsStore();

  useEffect(() => {
    if (auth?.user) {
      initUserWebSocket();
    }
    if (notifications) {
      setNotifications(notifications);
    }
  }, []);

  return (
    <AppShell
      navbar={{ width: 300, breakpoint: "sm", collapsed: { mobile: false } }}
      padding="4rem"
    >
      <Head title={title} />

      <FlashNotification />

      <Notifications />

      <AppShell.Navbar>
        <NavBarNested></NavBarNested>
      </AppShell.Navbar>

      <AppShell.Main>
        <ErrorBoundary>{children}</ErrorBoundary>
      </AppShell.Main>
    </AppShell>
  );
}
