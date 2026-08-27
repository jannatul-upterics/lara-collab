import logoImg from "../../images/logo.png";
import { Group, Image, Text } from "@mantine/core";

export default function Logo({ size = 32, withText = true, ...props }) {
  return (
    <Group wrap="nowrap" gap="xs" {...props}>
      <Image
        src={logoImg}
        alt="LaraCollab Logo"
        w={size}
        h={size}
        fit="contain"
      />
      {withText && (
        <Text fz={20} fw={600}>
          LaraCollab
        </Text>
      )}
    </Group>
  );
}
