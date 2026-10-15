import { router } from '@inertiajs/react';
import { Button, Container, Group, Paper, Stack, Text, Title } from '@mantine/core';
import { IconAlertTriangle, IconArrowLeft, IconRefresh } from '@tabler/icons-react';
import React from 'react';

export default class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  componentDidCatch(error, errorInfo) {
    console.error('ErrorBoundary caught an error:', error, errorInfo);
  }

  componentDidMount() {
    this.removeListener = router.on('navigate', () => {
      if (this.state.hasError) {
        this.setState({ hasError: false, error: null });
      }
    });
  }

  componentWillUnmount() {
    if (this.removeListener) {
      this.removeListener();
    }
  }

  handleReload = () => {
    window.location.reload();
  };

  handleGoBack = () => {
    if (window.history.length > 1) {
      window.history.back();
    } else {
      router.get(route('dashboard'));
    }
  };

  render() {
    if (this.state.hasError) {
      if (this.props.fallback) {
        return this.props.fallback;
      }

      return (
        <Container
          size='sm'
          py='xl'
        >
          <Paper
            withBorder
            p='xl'
            radius='md'
            shadow='sm'
          >
            <Stack
              align='center'
              gap='md'
            >
              <IconAlertTriangle
                size={48}
                color='var(--mantine-color-red-6)'
              />
              <Title
                order={2}
                ta='center'
              >
                Something went wrong
              </Title>
              <Text
                c='dimmed'
                ta='center'
                size='sm'
              >
                An unexpected error occurred while rendering this view. You can navigate back or
                reload the page.
              </Text>
              <Group mt='md'>
                <Button
                  variant='default'
                  leftSection={<IconArrowLeft size={16} />}
                  onClick={this.handleGoBack}
                >
                  Go Back
                </Button>
                <Button
                  variant='filled'
                  leftSection={<IconRefresh size={16} />}
                  onClick={this.handleReload}
                >
                  Reload Page
                </Button>
              </Group>
            </Stack>
          </Paper>
        </Container>
      );
    }

    return this.props.children;
  }
}
