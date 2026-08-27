import FlashNotification from "@/components/FlashNotification";
import Logo from "@/components/Logo";
import { Head } from "@inertiajs/react";
import { Center, Container } from "@mantine/core";

export default function GuestLayout({ title, children }) {
  return (
    <>
      <Head title={title} />

      <FlashNotification />

      <Container size={440} my={80}>
        <Center mb="lg">
          <Logo size={48} />
        </Center>
        {children}
      </Container>
    </>
  );
}
